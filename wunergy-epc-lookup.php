<?php
/**
 * Wunergy EPC Lookup
 * ------------------------------------------------------------------
 * Server-side integration with the UK Government "Get energy
 * performance of buildings data" API (epc.opendatacommunities.org).
 *
 * Why this has to run server-side, not in the browser:
 *  - The API requires HTTP Basic auth with a secret API key. Calling
 *    it from client-side JS would expose that key to every visitor.
 *  - The API has no CORS headers for arbitrary origins, so a direct
 *    browser fetch() would be blocked anyway.
 *
 * Flow this file implements:
 *  1. Visitor enters a postcode (and optionally a house number/name)
 *     in the widget.
 *  2. Widget JS calls our own WP AJAX endpoint (same-origin, no key
 *     exposed).
 *  3. This file calls the EPC API with Basic auth, finds the most
 *     recent VALID (< 10 years old) certificate for that address,
 *     and maps its free-text fabric descriptors into the same
 *     U-value shape used by the manual-entry calculator.
 *  4. JSON is returned to the widget, which pre-fills the property
 *     step instead of asking the homeowner to type everything.
 *
 * Setup required:
 *  - Register for a free API key: https://epc.opendatacommunities.org
 *  - Add to wp-config.php:
 *      define( 'WUNERGY_EPC_EMAIL', 'you@wunergy.co.uk' );
 *      define( 'WUNERGY_EPC_API_KEY', 'xxxxxxxxxxxxxxxx' );
 *  - Include/require this file from your plugin's main file.
 * ------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) exit; // no direct access

class Wunergy_Epc_Lookup {

    const API_BASE = 'https://epc.opendatacommunities.org/api/v1/domestic';
    const CACHE_TTL = DAY_IN_SECONDS; // avoid re-hitting the API for repeat postcode lookups
    const CERT_MAX_AGE_YEARS = 10;

    public function __construct() {
        add_action( 'wp_ajax_wunergy_epc_search',        [ $this, 'ajax_search' ] );
        add_action( 'wp_ajax_nopriv_wunergy_epc_search',  [ $this, 'ajax_search' ] ); // public-facing calculator
        add_action( 'wp_ajax_wunergy_epc_get',            [ $this, 'ajax_get_certificate' ] );
        add_action( 'wp_ajax_nopriv_wunergy_epc_get',     [ $this, 'ajax_get_certificate' ] );
    }

    /* -----------------------------------------------------------
     * STEP 1 — search by postcode, return a short address list
     * (a postcode usually covers several properties; let the
     * homeowner pick theirs before we fetch the full certificate)
     * ----------------------------------------------------------- */
    public function ajax_search() {
        check_ajax_referer( 'wunergy_calc_nonce', 'nonce' );

        $postcode = isset( $_POST['postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) : '';
        $postcode = strtoupper( preg_replace( '/\s+/', '', $postcode ) );

        if ( ! preg_match( '/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $postcode ) ) {
            wp_send_json_error( [ 'message' => 'Enter a valid UK postcode.' ], 400 );
        }

        $cache_key = 'wunergy_epc_search_' . md5( $postcode );
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            wp_send_json_success( $cached );
        }

        $response = $this->epc_request( '/search', [
            'postcode' => $postcode,
            'size'     => 50,
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ], 502 );
        }

        $rows = $response['rows'] ?? [];

        // Collapse to one entry per address, keeping only the most
        // recent lodgement — a property can have several historic EPCs.
        $by_address = [];
        foreach ( $rows as $row ) {
            $addr = $row['address'] ?? '';
            if ( ! $addr ) continue;
            $existing = $by_address[ $addr ] ?? null;
            if ( ! $existing || strtotime( $row['lodgement-date'] ) > strtotime( $existing['lodgement-date'] ) ) {
                $by_address[ $addr ] = $row;
            }
        }

        $results = array_values( array_map( function( $row ) {
            return [
                'lmk_key'        => $row['lmk-key'] ?? '',
                'address'        => $row['address'] ?? '',
                'lodgement_date' => $row['lodgement-date'] ?? '',
                'is_valid'       => $this->is_certificate_valid( $row['lodgement-date'] ?? '' ),
                'current_rating' => $row['current-energy-rating'] ?? null,
            ];
        }, $by_address ) );

        set_transient( $cache_key, $results, self::CACHE_TTL );
        wp_send_json_success( $results );
    }

    /* -----------------------------------------------------------
     * STEP 2 — fetch the full certificate by lmk-key and map it
     * into the calculator's data model
     * ----------------------------------------------------------- */
    public function ajax_get_certificate() {
        check_ajax_referer( 'wunergy_calc_nonce', 'nonce' );

        $lmk_key = isset( $_POST['lmk_key'] ) ? sanitize_text_field( wp_unslash( $_POST['lmk_key'] ) ) : '';
        if ( ! $lmk_key ) {
            wp_send_json_error( [ 'message' => 'Missing certificate reference.' ], 400 );
        }

        $cache_key = 'wunergy_epc_cert_' . md5( $lmk_key );
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            wp_send_json_success( $cached );
        }

        $response = $this->epc_request( '/certificate/' . rawurlencode( $lmk_key ), [] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ], 502 );
        }

        $cert = $response['rows'][0] ?? null;
        if ( ! $cert ) {
            wp_send_json_error( [ 'message' => 'Certificate not found.' ], 404 );
        }

        $mapped = $this->map_certificate_to_calculator_input( $cert );
        set_transient( $cache_key, $mapped, self::CACHE_TTL );
        wp_send_json_success( $mapped );
    }

    /* -----------------------------------------------------------
     * Core mapping: EPC free-text descriptors -> calculator inputs
     * ----------------------------------------------------------- */
    private function map_certificate_to_calculator_input( array $cert ): array {

        $age_band = $this->normalise_age_band( $cert['construction-age-band'] ?? '' );

        return [
            'lmk_key'          => $cert['lmk-key'] ?? '',
            'is_valid'         => $this->is_certificate_valid( $cert['lodgement-date'] ?? '' ),
            'lodgement_date'   => $cert['lodgement-date'] ?? '',
            'property_type'    => $cert['property-type'] ?? '',
            'built_form'       => $cert['built-form'] ?? '',
            'total_floor_area' => isset( $cert['total-floor-area'] ) ? (float) $cert['total-floor-area'] : null,
            'habitable_rooms'  => isset( $cert['number-habitable-rooms'] ) ? (int) $cert['number-habitable-rooms'] : null,
            'age_band'         => $age_band, // fallback for any element we can't infer from free text
            'u_values'         => [
                'wall'   => $this->infer_u_value( 'wall',   $cert['walls-description']   ?? '', $age_band ),
                'roof'   => $this->infer_u_value( 'roof',   $cert['roof-description']    ?? '', $age_band ),
                'floor'  => $this->infer_u_value( 'floor',  $cert['floor-description']   ?? '', $age_band ),
                'window' => $this->infer_u_value( 'window', $cert['windows-description'] ?? '', $age_band ),
            ],
            'raw_descriptions' => [ // surface these in the UI so the homeowner/surveyor can sanity-check
                'walls'   => $cert['walls-description']   ?? '',
                'roof'    => $cert['roof-description']    ?? '',
                'floor'   => $cert['floor-description']   ?? '',
                'windows' => $cert['windows-description'] ?? '',
            ],
        ];
    }

    /**
     * Keyword-match the EPC free-text description to an indicative
     * U-value. Falls back to the age-band default table (shared with
     * the manual calculator) when the text doesn't match a known
     * pattern or the field is empty/"NO DATA!".
     */
    private function infer_u_value( string $element, string $description, string $age_band ): array {
        $text = strtolower( trim( $description ) );
        $fallback = self::AGE_BAND_U_VALUES[ $age_band ][ $element ] ?? self::AGE_BAND_U_VALUES['1976-1990'][ $element ];

        if ( $text === '' || str_contains( $text, 'no data' ) ) {
            return [ 'value' => $fallback, 'source' => 'age_band_default' ];
        }

        $rules = self::DESCRIPTION_RULES[ $element ] ?? [];
        foreach ( $rules as $pattern => $value ) {
            if ( str_contains( $text, $pattern ) ) {
                return [ 'value' => $value, 'source' => 'epc_description' ];
            }
        }

        return [ 'value' => $fallback, 'source' => 'age_band_default_unmatched_text' ];
    }

    private function normalise_age_band( string $raw ): string {
        // EPC construction-age-band values look like "England and Wales: 1967-1975"
        if ( preg_match( '/(\d{4})/', $raw, $m ) ) {
            $year = (int) $m[1];
            if ( $year < 1900 ) return 'pre1900';
            if ( $year <= 1949 ) return '1900-1949';
            if ( $year <= 1975 ) return '1950-1975';
            if ( $year <= 1990 ) return '1976-1990';
            if ( $year <= 2006 ) return '1991-2006';
            if ( $year <= 2011 ) return '2007-2011';
            return '2012-plus';
        }
        return '1976-1990'; // conservative mid-range default when the band is unreadable
    }

    private function is_certificate_valid( string $lodgement_date ): bool {
        if ( ! $lodgement_date ) return false;
        $ts = strtotime( $lodgement_date );
        if ( ! $ts ) return false;
        return $ts > strtotime( '-' . self::CERT_MAX_AGE_YEARS . ' years' );
    }

    /* -----------------------------------------------------------
     * HTTP layer
     * ----------------------------------------------------------- */
    private function epc_request( string $path, array $query ) {
        if ( ! defined( 'WUNERGY_EPC_EMAIL' ) || ! defined( 'WUNERGY_EPC_API_KEY' ) ) {
            return new WP_Error( 'epc_config', 'EPC API credentials are not configured.' );
        }

        $url = self::API_BASE . $path;
        if ( $query ) $url .= '?' . http_build_query( $query );

        $auth = base64_encode( WUNERGY_EPC_EMAIL . ':' . WUNERGY_EPC_API_KEY );

        $response = wp_remote_get( $url, [
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
                'Accept'        => 'application/json',
            ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 401 ) return new WP_Error( 'epc_auth', 'EPC API authentication failed — check credentials.' );
        if ( $code === 404 ) return [ 'rows' => [] ];
        if ( $code >= 400 ) return new WP_Error( 'epc_http', 'EPC API returned an error (HTTP ' . $code . ').' );

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $body ) ? $body : [ 'rows' => [] ];
    }

    /* -----------------------------------------------------------
     * Reference tables (kept in sync with the manual-entry widget)
     * ----------------------------------------------------------- */
    const AGE_BAND_U_VALUES = [
        'pre1900'    => [ 'wall'=>2.10, 'roof'=>2.30, 'floor'=>1.20, 'window'=>4.80 ],
        '1900-1949'  => [ 'wall'=>1.60, 'roof'=>1.50, 'floor'=>1.00, 'window'=>4.80 ],
        '1950-1975'  => [ 'wall'=>1.50, 'roof'=>0.60, 'floor'=>0.70, 'window'=>4.80 ],
        '1976-1990'  => [ 'wall'=>1.00, 'roof'=>0.35, 'floor'=>0.50, 'window'=>2.80 ],
        '1991-2006'  => [ 'wall'=>0.45, 'roof'=>0.25, 'floor'=>0.35, 'window'=>2.00 ],
        '2007-2011'  => [ 'wall'=>0.30, 'roof'=>0.18, 'floor'=>0.22, 'window'=>1.60 ],
        '2012-plus'  => [ 'wall'=>0.18, 'roof'=>0.13, 'floor'=>0.13, 'window'=>1.40 ],
    ];

    // Keyword => indicative U-value. Order matters: more specific
    // patterns should appear before generic ones since the first
    // match wins. Extend this table as you see real EPC text.
    const DESCRIPTION_RULES = [
        'wall' => [
            'cavity wall, filled cavity'        => 0.55,
            'cavity wall, insulated'             => 0.55,
            'cavity wall, as built, insulated'   => 0.55,
            'cavity wall, no insulation'         => 1.50,
            'cavity wall, as built'              => 1.50,
            'solid brick, insulated (external)'  => 0.30,
            'solid brick, insulated (internal)'  => 0.35,
            'solid brick, insulated'             => 0.35,
            'solid brick, no insulation'         => 2.10,
            'solid brick'                        => 2.10,
            'timber frame, insulated'            => 0.35,
            'timber frame, as built'             => 1.10,
            'timber frame'                       => 0.60,
            'system built, insulated'            => 0.40,
            'system built'                       => 1.00,
            'granite or whinstone'               => 1.70,
            'sandstone or limestone'              => 1.70,
            'cob'                                 => 2.30,
        ],
        'roof' => [
            'pitched, insulated at rafters'      => 0.18,
            'pitched, 250 mm loft insulation'    => 0.16,
            'pitched, 200 mm loft insulation'    => 0.18,
            'pitched, 150 mm loft insulation'    => 0.20,
            'pitched, 100 mm loft insulation'    => 0.30,
            'pitched, 50 mm loft insulation'     => 0.55,
            'pitched, no insulation'             => 2.30,
            'pitched'                            => 0.35,
            'flat, insulated'                    => 0.25,
            'flat, no insulation'                => 2.30,
            'flat'                               => 1.50,
            'roof room(s), insulated'            => 0.30,
            'roof room(s)'                       => 1.00,
        ],
        'floor' => [
            'suspended, insulated'               => 0.35,
            'suspended, no insulation'            => 0.70,
            'suspended'                           => 0.70,
            'solid, insulated'                    => 0.25,
            'solid, no insulation'                => 0.70,
            'solid'                               => 0.70,
        ],
        'window' => [
            'triple glazed'                      => 1.00,
            'fully double glazed'                => 2.00,
            'mostly double glazed'               => 2.40,
            'partial double glazing'             => 3.20,
            'double glazing installed before 2002' => 2.80,
            'double glazing, unknown install date' => 2.80,
            'secondary glazing'                  => 2.40,
            'single glazed'                      => 4.80,
        ],
    ];
}

new Wunergy_Epc_Lookup();
