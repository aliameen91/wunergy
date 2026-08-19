<?php
/**
 * Wunergy Heat Loss Estimator — single-file version
 * ------------------------------------------------------------------
 * One PHP file that:
 *   - on GET: renders the calculator widget (HTML/CSS/JS)
 *   - on POST (with an `action` field): acts as its own JSON API for
 *     the EPC lookup, called by the page's own JS via fetch() to
 *     window.location.href — no separate endpoint/file needed.
 *
 * Deploy as-is on any PHP host, or drop into a WordPress page
 * template (the POST branch runs identically either way since it
 * only uses native PHP/cURL, not WordPress functions).
 *
 * Setup: sign up at https://get-energy-performance-data.communities.gov.uk/
 * (GOV.UK One Login) and paste your Bearer token into EPC_API_KEY below.
 * ------------------------------------------------------------------
 */

// ============================================================
// CONFIG
// ============================================================
define( 'EPC_API_KEY', getenv( 'EPC_API_KEY' ) ?: '' ); // Bearer token — set via the EPC_API_KEY environment variable, never hardcoded here
define( 'EPC_API_BASE', 'https://api.get-energy-performance-data.communities.gov.uk/api' ); // search: GET {base}/domestic/search?postcode=...  certificate: GET {base}/certificate?certificate_number=...
define( 'CERT_MAX_AGE_YEARS', 30 );

// RdSAP construction-age-band letter codes (England & Wales), as returned in
// sap_building_parts[].construction_age_band — mapped onto this app's 7 bands.
const SAP_AGE_BAND_MAP = [
        'A' => 'pre1900',
        'B' => '1900-1949', 'C' => '1900-1949',
        'D' => '1950-1975', 'E' => '1950-1975',
        'F' => '1976-1990', 'G' => '1976-1990',
        'H' => '1991-2006', 'I' => '1991-2006', 'J' => '1991-2006',
        'K' => '2007-2011',
        'L' => '2012-plus',
];

// ============================================================
// REFERENCE TABLES — shared by the EPC mapper and, in JS, by the
// manual room-by-room path. Indicative defaults, not the licensed
// CIBSE dataset or the certified MCS methodology.
// ============================================================
$AGE_BAND_U_VALUES = [
        'pre1900'   => [ 'wall' => 2.10, 'roof' => 2.30, 'floor' => 1.20, 'window' => 4.80, 'door' => 3.00 ],
        '1900-1949' => [ 'wall' => 1.60, 'roof' => 1.50, 'floor' => 1.00, 'window' => 4.80, 'door' => 3.00 ],
        '1950-1975' => [ 'wall' => 1.50, 'roof' => 0.60, 'floor' => 0.70, 'window' => 4.80, 'door' => 3.00 ],
        '1976-1990' => [ 'wall' => 1.00, 'roof' => 0.35, 'floor' => 0.50, 'window' => 2.80, 'door' => 3.00 ],
        '1991-2006' => [ 'wall' => 0.45, 'roof' => 0.25, 'floor' => 0.35, 'window' => 2.00, 'door' => 2.20 ],
        '2007-2011' => [ 'wall' => 0.30, 'roof' => 0.18, 'floor' => 0.22, 'window' => 1.60, 'door' => 2.00 ],
        '2012-plus' => [ 'wall' => 0.18, 'roof' => 0.13, 'floor' => 0.13, 'window' => 1.40, 'door' => 1.40 ],
];

$DESCRIPTION_RULES = [
        'wall' => [
                'cavity wall, filled cavity'         => 0.55,
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
                'sandstone or limestone'             => 1.70,
                'cob'                                => 2.30,
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
                'suspended, no insulation'           => 0.70,
                'suspended'                          => 0.70,
                'solid, insulated'                   => 0.25,
                'solid, no insulation'               => 0.70,
                'solid'                              => 0.70,
        ],
        'window' => [
                'triple glazed'                        => 1.00,
                'fully double glazed'                  => 2.00,
                'mostly double glazed'                 => 2.40,
                'partial double glazing'               => 3.20,
                'double glazing installed before 2002' => 2.80,
                'double glazing, unknown install date' => 2.80,
                'secondary glazing'                    => 2.40,
                'single glazed'                        => 4.80,
        ],
];

// Curated equipment catalogue — deliberately NOT scraped from a
// third-party retailer (legal/accuracy risk covered separately).
// Replace price_gbp with your actual trade/distributor pricing.
$EQUIPMENT_CATALOG = [
        [ 'brand'=>'Samsung',  'model'=>'Gen6 4kW Mono HT Quiet',  'capacity_kw'=>4,  'scop'=>4.2, 'price_gbp'=>3800 ],
        [ 'brand'=>'Samsung',  'model'=>'Gen6 6kW Mono HT Quiet',  'capacity_kw'=>6,  'scop'=>4.0, 'price_gbp'=>4300 ],
        [ 'brand'=>'Samsung',  'model'=>'Gen6 8kW Mono HT Quiet',  'capacity_kw'=>8,  'scop'=>3.9, 'price_gbp'=>4900 ],
        [ 'brand'=>'Samsung',  'model'=>'Gen6 12kW Mono HT Quiet', 'capacity_kw'=>12, 'scop'=>3.7, 'price_gbp'=>5800 ],
        [ 'brand'=>'Samsung',  'model'=>'Gen6 16kW Mono HT Quiet', 'capacity_kw'=>16, 'scop'=>3.6, 'price_gbp'=>6800 ],
        [ 'brand'=>'Vaillant', 'model'=>'aroTHERM plus 5kW',       'capacity_kw'=>5,  'scop'=>4.3, 'price_gbp'=>4100 ],
        [ 'brand'=>'Vaillant', 'model'=>'aroTHERM plus 7kW',       'capacity_kw'=>7,  'scop'=>4.1, 'price_gbp'=>4700 ],
        [ 'brand'=>'Vaillant', 'model'=>'aroTHERM plus 10kW',      'capacity_kw'=>10, 'scop'=>3.9, 'price_gbp'=>5400 ],
        [ 'brand'=>'Daikin',   'model'=>'Altherma 3 H HT 6kW',     'capacity_kw'=>6,  'scop'=>4.0, 'price_gbp'=>4400 ],
        [ 'brand'=>'Daikin',   'model'=>'Altherma 3 H HT 9kW',     'capacity_kw'=>9,  'scop'=>3.8, 'price_gbp'=>5300 ],
        [ 'brand'=>'Daikin',   'model'=>'Altherma 3 H HT 14kW',    'capacity_kw'=>14, 'scop'=>3.6, 'price_gbp'=>6400 ],
];

// Non-equipment cost bands (£) — installation labour, cylinder,
// electrical/pipework/commissioning. Indicative UK market ranges;
// replace with your own costed figures.
$INSTALL_COST_BANDS = [
        'cylinder' => [ 'low'=>700,  'high'=>1800 ],
        'labour'   => [ 'low'=>2000, 'high'=>4000 ],
        'extras'   => [ 'low'=>800,  'high'=>2000 ], // electrical, pipework, commissioning
];
define( 'BUS_GRANT_GBP', 7500 );

// ============================================================
// POST HANDLER — same page acts as its own JSON API
// ============================================================
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['action'] ) ) {
    header( 'Content-Type: application/json' );

    switch ( $_POST['action'] ) {
        case 'epc_search':
            echo json_encode( handle_epc_search( $_POST['postcode'] ?? '' ) );
            break;
        case 'epc_get':
            echo json_encode( handle_epc_get( $_POST['lmk_key'] ?? '' ) );
            break;
        default:
            echo json_encode( [ 'success' => false, 'message' => 'Unknown action.' ] );
    }
    exit;
}

// ============================================================
// EPC HELPERS
// ============================================================
function epc_request( string $path, array $query = [] ) {
    $url = EPC_API_BASE . $path;
    if ( $query ) $url .= '?' . http_build_query( $query );

    $ch = curl_init( $url );
    curl_setopt_array( $ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . EPC_API_KEY,
                    'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
    ] );
    $body = curl_exec( $ch );
    $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    $curl_err = curl_error( $ch );
    curl_close( $ch );

    if ( $curl_err )      return [ 'error' => 'Request failed: ' . $curl_err ];
    if ( $code === 401 )  return [ 'error' => 'EPC API authentication failed — check credentials.' ];
    if ( $code === 404 )  return [ 'rows' => [] ];
    if ( $code >= 400 )   return [ 'error' => 'EPC API returned HTTP ' . $code ];

    $data = json_decode( $body, true );
    return is_array( $data ) ? $data : [ 'rows' => [] ];
}

function is_certificate_valid( string $lodgement_date ): bool {
    if ( ! $lodgement_date ) return false;
    $ts = strtotime( $lodgement_date );
    if ( ! $ts ) return false;
    return $ts > strtotime( '-' . CERT_MAX_AGE_YEARS . ' years' );
}

function normalise_age_band( string $raw ): string {
    return SAP_AGE_BAND_MAP[ strtoupper( trim( $raw ) ) ] ?? '1976-1990';
}

function infer_u_value( string $element, string $description, string $age_band ): array {
    global $AGE_BAND_U_VALUES, $DESCRIPTION_RULES;
    $text = strtolower( trim( $description ) );
    $fallback = $AGE_BAND_U_VALUES[ $age_band ][ $element ] ?? $AGE_BAND_U_VALUES['1976-1990'][ $element ];

    if ( $text === '' || str_contains( $text, 'no data' ) ) {
        return [ 'value' => $fallback, 'source' => 'age_band_default' ];
    }
    foreach ( $DESCRIPTION_RULES[ $element ] ?? [] as $pattern => $value ) {
        if ( str_contains( $text, $pattern ) ) {
            return [ 'value' => $value, 'source' => 'epc_description' ];
        }
    }
    return [ 'value' => $fallback, 'source' => 'age_band_default_unmatched_text' ];
}

function format_epc_address( array $row ): string {
    $parts = array_filter( [
            $row['addressLine1'] ?? null,
            $row['addressLine2'] ?? null,
            $row['addressLine3'] ?? null,
            $row['addressLine4'] ?? null,
            $row['postTown'] ?? null,
    ] );
    return implode( ', ', $parts );
}

// The live API sometimes wraps a value with its unit/currency (e.g.
// {"value":1000,"currency":"GBP"}) and sometimes returns it bare — handle both.
function epc_number( $field ): ?float {
    if ( is_array( $field ) ) return isset( $field['value'] ) ? (float) $field['value'] : null;
    return is_numeric( $field ) ? (float) $field : null;
}
function epc_text( $field ): string {
    if ( is_array( $field ) ) return (string) ( $field['value'] ?? '' );
    return (string) ( $field ?? '' );
}

// walls/roofs/floors can list more than one entry (e.g. an extension built in
// a different era) — infer a U-value per entry and average them for the
// calculator's math, while keeping every entry visible for display.
function map_epc_element_group( string $element, array $entries, string $age_band ): array {
    if ( ! $entries ) $entries = [ [] ];
    $mapped = array_map( function ( $entry ) use ( $element, $age_band ) {
        $desc = epc_text( $entry['description'] ?? '' );
        $inferred = infer_u_value( $element, $desc, $age_band );
        return [
                'description'             => $desc,
                'u_value'                 => $inferred['value'],
                'source'                  => $inferred['source'],
                'epc_efficiency_rating'   => $entry['energy_efficiency_rating'] ?? null,
        ];
    }, $entries );

    return [
            'value'   => round( array_sum( array_column( $mapped, 'u_value' ) ) / count( $mapped ), 3 ),
            'source'  => $mapped[0]['source'],
            'entries' => $mapped,
    ];
}

function build_calculator_input_from_cert( array $cert, string $certificate_number ): array {
    $age_band_code = $cert['sap_building_parts'][0]['construction_age_band'] ?? '';
    $age_band = normalise_age_band( $age_band_code );

    $wall  = map_epc_element_group( 'wall',   $cert['walls']  ?? [], $age_band );
    $roof  = map_epc_element_group( 'roof',   $cert['roofs']  ?? [], $age_band );
    $floor = map_epc_element_group( 'floor',  $cert['floors'] ?? [], $age_band );
    $window = map_epc_element_group( 'window', isset( $cert['window'] ) ? [ $cert['window'] ] : [], $age_band );

    $describe_all = fn( array $entries ) => array_map(
        fn( $e ) => epc_text( $e['description'] ?? '' ),
        $entries
    );

    return [
            'lmk_key'          => $certificate_number,
            'is_valid'         => is_certificate_valid( $cert['registration_date'] ?? '' ),
            'lodgement_date'   => $cert['registration_date'] ?? '',
            'property_type'    => $cert['dwelling_type'] ?? '',
            'total_floor_area' => isset( $cert['total_floor_area'] ) ? (float) $cert['total_floor_area'] : null,
            'habitable_rooms'  => isset( $cert['habitable_room_count'] ) ? (int) $cert['habitable_room_count'] : null,
            'age_band'         => $age_band,
            'u_values'         => [
                    'wall'   => [ 'value' => $wall['value'],   'source' => $wall['source'] ],
                    'roof'   => [ 'value' => $roof['value'],   'source' => $roof['source'] ],
                    'floor'  => [ 'value' => $floor['value'],  'source' => $floor['source'] ],
                    'window' => [ 'value' => $window['value'], 'source' => $window['source'] ],
            ],
            'raw_descriptions' => [
                    'walls'   => implode( '; ', array_filter( $describe_all( $cert['walls']  ?? [] ) ) ) ?: 'No data',
                    'roof'    => implode( '; ', array_filter( $describe_all( $cert['roofs']  ?? [] ) ) ) ?: 'No data',
                    'floor'   => implode( '; ', array_filter( $describe_all( $cert['floors'] ?? [] ) ) ) ?: 'No data',
                    'windows' => $window['entries'][0]['description'] ?: 'No data',
            ],
            // full per-entry breakdown (multiple walls/roofs/floors, each with its
            // own inferred U-value and the EPC's own efficiency rating for it)
            'fabric_detail'    => [
                    'wall'   => $wall['entries'],
                    'roof'   => $roof['entries'],
                    'floor'  => $floor['entries'],
                    'window' => $window['entries'],
            ],
            'epc_rating' => [
                    'current_band'    => $cert['current_energy_efficiency_band']   ?? null,
                    'current_score'   => $cert['energy_rating_current']            ?? null,
                    'potential_band'  => $cert['potential_energy_efficiency_band'] ?? null,
                    'potential_score' => $cert['energy_rating_potential']          ?? null,
            ],
            'co2_emissions_tonnes_per_year' => [
                    'current'   => $cert['co2_emissions_current']   ?? null,
                    'potential' => $cert['co2_emissions_potential'] ?? null,
            ],
            'energy_consumption_kwh_per_m2' => [
                    'current'   => $cert['energy_consumption_current']   ?? null,
                    'potential' => $cert['energy_consumption_potential'] ?? null,
            ],
            'annual_running_cost_gbp' => [
                    'heating'   => [ 'current' => epc_number( $cert['heating_cost_current']   ?? null ), 'potential' => epc_number( $cert['heating_cost_potential']   ?? null ) ],
                    'hot_water' => [ 'current' => epc_number( $cert['hot_water_cost_current']  ?? null ), 'potential' => epc_number( $cert['hot_water_cost_potential']  ?? null ) ],
                    'lighting'  => [ 'current' => epc_number( $cert['lighting_cost_current']   ?? null ), 'potential' => epc_number( $cert['lighting_cost_potential']   ?? null ) ],
            ],
            'heating_system' => [
                    'main'      => array_values( array_filter( array_map( fn( $h ) => epc_text( $h['description'] ?? '' ), $cert['main_heating'] ?? [] ) ) ),
                    'secondary' => epc_text( $cert['secondary_heating']['description'] ?? '' ),
                    'hot_water' => epc_text( $cert['hot_water']['description']         ?? '' ),
                    'lighting'  => epc_text( $cert['lighting']['description']          ?? '' ),
                    'controls'  => array_values( array_filter( array_map( fn( $c ) => epc_text( $c['description'] ?? '' ), $cert['main_heating_controls'] ?? [] ) ) ),
            ],
            'suggested_improvements' => array_map( function ( $imp ) {
                return [
                        'sequence'             => $imp['sequence'] ?? null,
                        'type_code'            => $imp['improvement_type'] ?? null,
                        'typical_saving_gbp'   => epc_number( $imp['typical_saving'] ?? null ),
                        'indicative_cost'      => $imp['indicative_cost'] ?? null,
                        'resulting_epc_score'  => $imp['energy_performance_rating'] ?? null,
                ];
            }, $cert['suggested_improvements'] ?? [] ),
    ];
}

function handle_epc_search( string $postcode_raw ): array {
    $postcode = strtoupper( preg_replace( '/\s+/', '', $postcode_raw ) );
    if ( ! preg_match( '/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $postcode ) ) {
        return [ 'success' => false, 'message' => 'Enter a valid UK postcode.' ];
    }
    // the API expects the standard "outward inward" format (e.g. "MK6 5AB"), not the stripped form
    $postcode_formatted = substr( $postcode, 0, -3 ) . ' ' . substr( $postcode, -3 );

    $result = epc_request( '/domestic/search', [ 'postcode' => $postcode_formatted, 'current_page' => 1, 'page_size' => 50 ] );
    if ( isset( $result['error'] ) ) return [ 'success' => false, 'message' => $result['error'] ];

    $by_address = [];
    foreach ( $result['data'] ?? [] as $row ) {
        $addr = format_epc_address( $row );
        if ( ! $addr ) continue;
        if ( ! isset( $by_address[ $addr ] ) || strtotime( $row['registrationDate'] ) > strtotime( $by_address[ $addr ]['registrationDate'] ) ) {
            $by_address[ $addr ] = $row;
        }
    }

    $results = array_map( function ( $row ) {
        return [
                'lmk_key'        => $row['certificateNumber'] ?? '',
                'address'        => format_epc_address( $row ),
                'lodgement_date' => $row['registrationDate'] ?? '',
                'is_valid'       => is_certificate_valid( $row['registrationDate'] ?? '' ),
                'current_rating' => $row['currentEnergyEfficiencyBand'] ?? null,
        ];
    }, array_values( $by_address ) );

    return [ 'success' => true, 'data' => $results ];
}

function handle_epc_get( string $lmk_key ): array {
    if ( ! $lmk_key ) return [ 'success' => false, 'message' => 'Missing certificate reference.' ];

    $result = epc_request( '/certificate', [ 'certificate_number' => $lmk_key ] );
    if ( isset( $result['error'] ) ) return [ 'success' => false, 'message' => $result['error'] ];

    $cert = $result['data'] ?? null;
    if ( ! $cert ) return [ 'success' => false, 'message' => 'Certificate not found.' ];

    return [ 'success' => true, 'data' => build_calculator_input_from_cert( $cert, $lmk_key ) ];
}

// ============================================================
// GET — render the widget. JS below carries the SAME age-band
// U-value table (json_encode'd from PHP so the two never drift
// apart) and posts to window.location.href for EPC lookups.
// ============================================================
$age_band_u_values_json = json_encode( $AGE_BAND_U_VALUES );
$description_rules_json = json_encode( $DESCRIPTION_RULES );
$equipment_catalog_json = json_encode( $EQUIPMENT_CATALOG );
$install_cost_bands_json = json_encode( $INSTALL_COST_BANDS );
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heat Loss Estimator — Wunergy</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
    <style>
        :root{
            --bg:#F5F7FA; --surface:#FFFFFF; --surface-alt:#EEF1F6; --border:#DCE2EA;
            --text:#1A2333; --text-muted:#5B6472;
            --cold:#2E5EAA; --warm:#E2672B;
            --gradient:linear-gradient(90deg,var(--cold) 0%,#7C6FBE 50%,var(--warm) 100%);
            --accent2:#0F766E; --danger:#C1432D; --radius:14px; --radius-sm:10px;
            --shadow:0 1px 3px rgba(20,30,50,.05), 0 10px 28px -14px rgba(20,30,50,.16);
        }
        *{box-sizing:border-box;}
        body{margin:0;background:var(--bg);color:var(--text);font-family:'Inter',system-ui,sans-serif;-webkit-font-smoothing:antialiased;}
        .wrap{max-width:780px;margin:0 auto;padding:44px 24px 80px;}
        h1,h2,h3{font-family:'Space Grotesk',system-ui,sans-serif;margin:0;letter-spacing:-0.01em;}
        .eyebrow{font-family:'IBM Plex Mono',monospace;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--cold);font-weight:600;margin-bottom:8px;display:block;}
        header.top{margin-bottom:36px;padding-bottom:28px;border-bottom:1.5px solid var(--border);}
        header.top h1{font-size:26px;font-weight:600;}
        header.top p{color:var(--text-muted);font-size:14px;margin-top:8px;max-width:52ch;line-height:1.6;}
        .steps{display:flex;align-items:center;gap:0;margin:28px 0 36px;}
        .step-item{display:flex;align-items:center;flex:1;}
        .step-dot{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'IBM Plex Mono',monospace;font-size:12px;font-weight:600;background:var(--surface-alt);color:var(--text-muted);border:1.5px solid var(--border);flex-shrink:0;transition:all .25s ease;}
        .step-item.active .step-dot{background:var(--cold);border-color:var(--cold);color:#fff;}
        .step-item.done .step-dot{background:var(--accent2);border-color:var(--accent2);color:#fff;}
        .step-label{font-size:12px;color:var(--text-muted);margin-left:8px;white-space:nowrap;}
        .step-item.active .step-label{color:var(--text);font-weight:600;}
        .step-line{flex:1;height:2px;background:var(--border);margin:0 10px;position:relative;overflow:hidden;}
        .step-line.filled::after{content:'';position:absolute;inset:0;background:var(--gradient);}
        .card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:26px 28px;margin-bottom:20px;}
        .field-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
        .field{display:flex;flex-direction:column;gap:6px;}
        .field.full{grid-column:1 / -1;}
        label{font-size:12.5px;font-weight:600;color:var(--text);}
        label .hint{font-weight:400;color:var(--text-muted);}
        input[type="text"],input[type="number"],select{font-family:'Inter',sans-serif;font-size:14px;padding:9px 11px;border:1.5px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);width:100%;}
        input:focus-visible,select:focus-visible,button:focus-visible{outline:2.5px solid var(--cold);outline-offset:1px;}
        .checkrow{display:flex;align-items:center;gap:8px;font-size:13.5px;font-weight:500;}
        .checkrow input{width:16px;height:16px;accent-color:var(--warm);}
        .btn{font-family:'Inter',sans-serif;font-weight:600;font-size:14px;padding:11px 20px;border-radius:8px;border:none;cursor:pointer;transition:transform .1s ease, opacity .15s ease;}
        .btn:active{transform:scale(.98);}
        .btn-primary{background:var(--text);color:#fff;}
        .btn-primary:hover{opacity:.88;}
        .btn-primary:disabled{background:var(--border);color:var(--text-muted);cursor:not-allowed;}
        .btn-ghost{background:transparent;color:var(--text-muted);border:1.5px solid var(--border);}
        .btn-ghost:hover{border-color:var(--text-muted);color:var(--text);}
        .btn-danger{background:transparent;color:var(--danger);border:1.5px solid transparent;padding:6px 10px;font-size:12.5px;}
        .btn-danger:hover{background:#FBEAE6;}
        .btn-row{display:flex;justify-content:space-between;margin-top:28px;gap:12px;}
        .btn-sm{padding:8px 14px;font-size:13px;}
        .room-card{border:1.5px solid var(--border);border-radius:var(--radius);margin-bottom:16px;overflow:hidden;}
        .room-head{display:flex;justify-content:space-between;align-items:center;padding:16px 18px;background:var(--surface-alt);cursor:pointer;}
        .room-head-left{display:flex;align-items:center;gap:10px;}
        .room-num{font-family:'IBM Plex Mono',monospace;font-size:11px;font-weight:600;width:22px;height:22px;border-radius:6px;background:var(--text);color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
        .room-name{font-weight:600;font-size:14.5px;}
        .room-meta{font-family:'IBM Plex Mono',monospace;font-size:11.5px;color:var(--text-muted);margin-left:2px;}
        .chevron{transition:transform .2s ease;color:var(--text-muted);}
        .room-card.collapsed .chevron{transform:rotate(-90deg);}
        .room-body{padding:18px;}
        .room-card.collapsed .room-body{display:none;}
        .live-calc{font-family:'IBM Plex Mono',monospace;font-size:12px;color:var(--cold);background:var(--surface-alt);padding:8px 10px;border-radius:6px;margin-top:10px;}
        .add-room-btn{width:100%;padding:16px;border-radius:var(--radius);border:1.5px dashed var(--border);background:transparent;color:var(--text-muted);font-weight:600;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:4px;}
        .add-room-btn:hover{border-color:var(--cold);color:var(--cold);}
        .summary-card{background:var(--text);color:#fff;border-radius:var(--radius);padding:32px 28px;margin-bottom:20px;position:relative;overflow:hidden;}
        .summary-card::before{content:'';position:absolute;inset:0;background:var(--gradient);opacity:.18;}
        .summary-inner{position:relative;}
        .summary-eyebrow{font-family:'IBM Plex Mono',monospace;font-size:11px;letter-spacing:.14em;text-transform:uppercase;opacity:.7;}
        .summary-value{font-family:'Space Grotesk',sans-serif;font-size:44px;font-weight:700;margin:6px 0 2px;line-height:1;}
        .summary-value span{font-size:18px;font-weight:500;opacity:.75;margin-left:6px;}
        .summary-sub{font-size:13px;opacity:.75;margin-top:8px;line-height:1.5;}
        .room-result-row{display:grid;grid-template-columns:1.3fr .6fr .5fr 1.5fr;gap:10px;align-items:center;padding:11px 0;border-bottom:1px solid var(--border);font-size:13.5px;}
        .room-result-row:last-child{border-bottom:none;}
        .room-result-row.header-row{font-family:'IBM Plex Mono',monospace;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);font-weight:600;padding-bottom:8px;}
        .rr-name{font-weight:600;}
        .rr-value{font-family:'IBM Plex Mono',monospace;font-weight:600;}
        .heat-bar-track{background:var(--surface-alt);border-radius:5px;height:9px;overflow:hidden;}
        .heat-bar-fill{height:100%;background:var(--gradient);border-radius:5px;}
        .caveat{font-size:12.5px;color:var(--text-muted);line-height:1.6;background:var(--surface-alt);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px 16px;margin-top:16px;}
        .caveat strong{color:var(--text);}
        .error-text{color:var(--danger);font-size:12.5px;margin-top:8px;}
        .badge{font-family:'IBM Plex Mono',monospace;font-size:10.5px;font-weight:600;padding:3px 8px;border-radius:20px;background:var(--surface-alt);color:var(--text-muted);}
        .badge.good{background:#E7F4F1;color:var(--accent2);}
        .badge.warn{background:#FBEAE6;color:var(--danger);}
        .epc-box{border:1.5px solid var(--border);border-radius:var(--radius);padding:18px 20px;margin-top:16px;}
        .epc-address-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);font-size:13.5px;}
        .epc-address-row:last-child{border-bottom:none;}
        .spinner{display:inline-block;width:14px;height:14px;border:2px solid var(--border);border-top-color:var(--cold);border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-right:6px;}
        @keyframes spin{to{transform:rotate(360deg);}}
        .uval-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:14px;}
        .uval-cell{background:var(--surface-alt);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 10px;text-align:center;}
        .uval-cell .k{font-size:10.5px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;}
        .uval-cell .v{font-family:'IBM Plex Mono',monospace;font-weight:600;font-size:14px;margin-top:2px;}
        .uval-cell .src{font-size:10px;color:var(--text-muted);margin-top:3px;}
        .raw-desc-list{margin-top:14px;border-top:1px solid var(--border);padding-top:10px;}
        .raw-desc-row{display:grid;grid-template-columns:70px 1fr;gap:10px;padding:5px 0;font-size:12px;}
        .raw-desc-row .rd-k{color:var(--text-muted);font-weight:600;text-transform:uppercase;font-size:10.5px;letter-spacing:.04em;padding-top:1px;}
        .raw-desc-row .rd-v{color:var(--text);}
        .equip-card{border:1.5px solid var(--border);border-radius:var(--radius);padding:18px;display:flex;justify-content:space-between;align-items:center;gap:14px;margin-top:14px;}
        .equip-card.primary{border-color:var(--warm);background:linear-gradient(180deg,#FFF8F3,var(--surface));}
        .equip-name{font-weight:600;font-size:14.5px;}
        .equip-meta{font-size:12px;color:var(--text-muted);margin-top:3px;}
        .equip-price{font-family:'IBM Plex Mono',monospace;font-weight:700;font-size:17px;white-space:nowrap;}
        .cost-row{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border);font-size:13.5px;}
        .cost-row:last-child{border-bottom:none;}
        .cost-row .label{color:var(--text-muted);}
        .cost-row .value{font-family:'IBM Plex Mono',monospace;font-weight:600;}
        .cost-row.grant .value{color:var(--accent2);}
        .cost-row.net{border-top:2px solid var(--text);margin-top:4px;padding-top:12px;font-size:15px;}
        .cost-row.net .value{font-size:19px;font-weight:700;}
        @media(max-width:560px){
            .field-grid{grid-template-columns:1fr;}
            .step-label{display:none;}
            .summary-value{font-size:34px;}
            .room-result-row{grid-template-columns:1fr .5fr .5fr;}
            .room-result-row > :nth-child(4){display:none;}
            .uval-grid{grid-template-columns:repeat(2,1fr);}
        }
    </style>
</head>
<body>
<div class="wrap">
    <header class="top">
        <span class="eyebrow">Wunergy · Design Estimate</span>
        <h1>Heat Loss Estimator</h1>
        <p>Check for a valid EPC first — we'll pull your construction details automatically. No current certificate? Enter room details manually for the same indicative estimate.</p>
    </header>
    <div class="steps" id="stepIndicator"></div>
    <div id="stepContent"></div>
</div>

<script>
    const AGE_BAND_U_VALUES = <?php echo $age_band_u_values_json; ?>;
    const DESCRIPTION_RULES = <?php echo $description_rules_json; ?>;
    const EQUIPMENT_CATALOG = <?php echo $equipment_catalog_json; ?>;
    const INSTALL_COST_BANDS = <?php echo $install_cost_bands_json; ?>;
    const BUS_GRANT_GBP = <?php echo BUS_GRANT_GBP; ?>;
    const POST_URL = window.location.href; // same page handles POST as its own JSON API

    const AGE_BANDS = [
        {id:'pre1900',  label:'Before 1900'},
        {id:'1900-1949',label:'1900 – 1949'},
        {id:'1950-1975',label:'1950 – 1975'},
        {id:'1976-1990',label:'1976 – 1990'},
        {id:'1991-2006',label:'1991 – 2006'},
        {id:'2007-2011',label:'2007 – 2011'},
        {id:'2012-plus',label:'2012 onwards'}
    ];
    const INFILTRATION_MULT = { 'pre1900':2.0,'1900-1949':1.6,'1950-1975':1.3,'1976-1990':1.1,'1991-2006':1.0,'2007-2011':0.8,'2012-plus':0.6 };
    const ROOM_TYPES = {
        living:   {label:'Living / Dining room', internalTemp:21, baseAch:0.5},
        bedroom:  {label:'Bedroom',              internalTemp:18, baseAch:0.5},
        bathroom: {label:'Bathroom / En-suite',  internalTemp:22, baseAch:1.5},
        kitchen:  {label:'Kitchen',              internalTemp:18, baseAch:1.5},
        hall:     {label:'Hallway / Landing',    internalTemp:18, baseAch:1.0},
        other:    {label:'Other habitable room', internalTemp:18, baseAch:0.5}
    };
    const POSTCODE_DESIGN_TEMP = {
        AB:-6.4, DD:-5.1, EH:-4.6, G:-4.6, KA:-4.3, KY:-4.9, PA:-4.6, FK:-5.1, BT:-3.5,
        LL:-3.7, SA:-3.4, CF:-3.2, NP:-3.2, NE:-4.4, SR:-4.4, DH:-4.4, DL:-4.6, TS:-4.0,
        LS:-4.3, BD:-4.5, HD:-4.3, HX:-4.3, WF:-4.2, YO:-4.6, HG:-4.6, S:-4.2, DN:-3.9,
        M:-3.8, OL:-4.0, BL:-4.0, WN:-3.8, SK:-3.8, WA:-3.5, L:-3.4, PR:-4.0, FY:-4.0, LA:-4.2,
        CH:-3.4, CW:-3.6, ST:-4.0, DE:-4.0, NG:-3.9, LN:-3.7, LE:-3.7,
        B:-3.6, WS:-3.8, WV:-3.9, DY:-3.8, CV:-3.6, WR:-3.5, HR:-3.6, TF:-3.9, SY:-3.9,
        NR:-3.3, IP:-3.3, CB:-3.5, PE:-3.6, CO:-3.2,
        NN:-3.6, MK:-3.4, LU:-3.2, HP:-3.2, OX:-3.4, RG:-3.2, SL:-3.0, GU:-3.0,
        SN:-3.4, GL:-3.6, BS:-3.4, BA:-3.4, TA:-3.5, DT:-3.0, BH:-2.9, SP:-3.2,
        EX:-2.8, PL:-2.5, TQ:-2.6, TR:-1.9, CT:-2.6, ME:-2.8, DA:-2.9, TN:-2.9, RH:-2.9,
        BN:-2.7, PO:-2.9, SO:-2.9, E:-2.9, EC:-2.9, WC:-2.9, N:-3.0, NW:-3.0, SE:-2.9, SW:-2.9, W:-2.9, KT:-2.9,
        IG:-2.9, RM:-2.9, CR:-2.9, BR:-2.9, HA:-3.0, UB:-3.0, TW:-2.9, EN:-3.1, WD:-3.1
    };
    const DEFAULT_DESIGN_TEMP = -3.7;

    /* ---------------- STATE ---------------- */
    let state = {
        step: 0,
        property: { postcode:'', ageBand:'1976-1990', altitude:0, epc:null, construction:{ wall:'', roof:'', floor:'', window:'' } },
        rooms: [],
        roomsManuallyEdited: false // true once the user touches a room field/add/remove — stops EPC selection from overwriting their edits
    };
    let epcUi = { status:'idle', message:'', addresses:[] }; // idle | loading | list | error
    let roomCounter = 0;

    function newRoom(){
        roomCounter++;
        return { id: roomCounter, name:'Room '+roomCounter, type:'living', length:'', width:'', height:2.4,
            extWallArea:'', windowArea:'', hasDoor:false, doorArea:1.85, groundFloor:false, topFloor:false, collapsed:false };
    }
    state.rooms.push(newRoom(), newRoom());
    state.rooms[0].name = 'Living Room';
    state.rooms[1].name = 'Bedroom 1'; state.rooms[1].type = 'bedroom';

    // EPC gives a room COUNT (habitable_rooms) and a whole-house floor
    // area, never per-room geometry — so this can only seed a starting
    // set of rooms (evenly split area, square footprint guess), not a
    // real room-by-room survey. The user still has to fill in wall/
    // window areas and correct dimensions per room.
    function typesForCount(count){
        const base = [];
        if(count>=1) base.push('living');
        if(count>=2) base.push('kitchen');
        if(count>=3) base.push('bathroom');
        while(base.length < count) base.push('bedroom');
        return base.slice(0, count);
    }
    function generateRoomsFromEpc(epc){
        const count = (epc.habitable_rooms && epc.habitable_rooms > 0) ? epc.habitable_rooms : 4;
        const totalArea = epc.total_floor_area || (count * 15);
        const avgArea = totalArea / count;
        const side = Math.round(Math.sqrt(avgArea) * 10) / 10;
        const types = typesForCount(count);
        let bedroomN = 0;
        return types.map(type => {
            roomCounter++;
            if(type === 'bedroom') bedroomN++;
            return {
                id: roomCounter,
                name: ROOM_TYPES[type].label + (type === 'bedroom' ? ' ' + bedroomN : ''),
                type, length: side, width: side, height: 2.4,
                extWallArea:'', windowArea:'', hasDoor:false, doorArea:1.85,
                groundFloor:false, topFloor:false, collapsed:false
            };
        });
    }

    /* ---------------- RENDER SHELL ---------------- */
    const STEP_LABELS = ['Property', 'Rooms', 'Results'];
    function render(){
        renderSteps();
        if(state.step === 0) renderPropertyStep();
        else if(state.step === 1) renderRoomsStep();
        else renderResultsStep();
    }
    function renderSteps(){
        let html = '';
        STEP_LABELS.forEach((label,i)=>{
            const cls = i < state.step ? 'done' : (i === state.step ? 'active' : '');
            html += `<div class="step-item ${cls}"><div class="step-dot">${i < state.step ? '✓' : i+1}</div><div class="step-label">${label}</div></div>`;
            if(i < STEP_LABELS.length-1) html += `<div class="step-line ${i < state.step ? 'filled':''}"></div>`;
        });
        document.getElementById('stepIndicator').innerHTML = html;
    }
    function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function fmtGbp(n){ return (n===null||n===undefined) ? '—' : '£'+Math.round(n).toLocaleString(); }
    function fmtNum(n,unit){ return (n===null||n===undefined) ? '—' : n+(unit||''); }

    /* ---------------- STEP 0: PROPERTY + EPC ---------------- */
    function renderPropertyStep(){
        const p = state.property;
        document.getElementById('stepContent').innerHTML = `
    <div class="card">
      <h3 style="margin-bottom:6px;">Check for an EPC</h3>
      <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px;">We'll look up construction details for this address if a valid certificate (less than 10 years old) exists.</p>
      <div class="field-grid">
        <div class="field full">
          <label>Postcode</label>
          <input type="text" id="f-postcode" placeholder="e.g. LS6 3AB" value="${escapeHtml(p.postcode)}">
        </div>
      </div>
      <button class="btn btn-primary btn-sm" id="epcCheckBtn" style="margin-top:12px;" onclick="checkEpc()">Check for EPC</button>
      <div id="epcResultArea"></div>
    </div>

    <div class="card">
      <h3 style="margin-bottom:16px;">Property details</h3>
      <div class="field-grid">
        <div class="field">
          <label>Construction age band ${p.epc && p.epc.is_valid ? '<span class="hint">(from EPC — override if needed)</span>' : ''}</label>
          <select id="f-ageband">
            ${AGE_BANDS.map(b=>`<option value="${b.id}" ${b.id===p.ageBand?'selected':''}>${b.label}</option>`).join('')}
          </select>
        </div>
        <div class="field">
          <label>Altitude above sea level <span class="hint">(m, optional)</span></label>
          <input type="number" id="f-altitude" min="0" value="${p.altitude}">
        </div>
      </div>
    </div>

    <div class="card">
      <h3 style="margin-bottom:6px;">Construction details <span class="hint">(optional)</span></h3>
      <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px;">
        ${p.epc && p.epc.is_valid
            ? 'A valid EPC was found, so its own construction descriptions are used instead of these — no need to fill them in.'
            : 'No EPC on file, so every room uses the age-band default U-values below. If you know the actual wall/roof/floor/window construction, pick it here for a more accurate estimate — these are the same construction categories an EPC assessor records.'}
      </p>
      <div class="field-grid">
        ${['wall','roof','floor','window'].map(el => `
        <div class="field">
          <label>${el.charAt(0).toUpperCase()+el.slice(1)} construction</label>
          <select id="f-construction-${el}" ${p.epc && p.epc.is_valid ? 'disabled' : ''}>
            <option value="">Use age-band default</option>
            ${Object.keys(DESCRIPTION_RULES[el]).map(k => `<option value="${escapeHtml(k)}" ${p.construction[el]===k?'selected':''}>${escapeHtml(k.charAt(0).toUpperCase()+k.slice(1))}</option>`).join('')}
          </select>
        </div>`).join('')}
      </div>
    </div>
    <div class="btn-row">
      <div></div>
      <button class="btn btn-primary" onclick="goStep(1)">Continue to rooms →</button>
    </div>
  `;
        document.getElementById('f-postcode').oninput = e => state.property.postcode = e.target.value;
        document.getElementById('f-ageband').onchange = e => state.property.ageBand = e.target.value;
        document.getElementById('f-altitude').oninput = e => state.property.altitude = parseFloat(e.target.value)||0;
        ['wall','roof','floor','window'].forEach(el => {
            const sel = document.getElementById(`f-construction-${el}`);
            if(sel) sel.onchange = e => state.property.construction[el] = e.target.value;
        });
        renderEpcResultArea();
    }

    async function postJson(action, params){
        const body = new URLSearchParams({ action, ...params });
        const res = await fetch(POST_URL, { method:'POST', body });
        return res.json();
    }

    async function checkEpc(){
        if(!state.property.postcode.trim()){ epcUi = {status:'error', message:'Enter a postcode first.', addresses:[]}; renderEpcResultArea(); return; }
        epcUi = { status:'loading', message:'', addresses:[] };
        renderEpcResultArea();
        const json = await postJson('epc_search', { postcode: state.property.postcode });
        if(!json.success){
            epcUi = { status:'error', message: json.message || 'Lookup failed.', addresses:[] };
        } else if(json.data.length === 0){
            epcUi = { status:'error', message:'No EPC found for that postcode. Use the manual room-by-room path.', addresses:[] };
        } else {
            epcUi = { status:'list', message:'', addresses: json.data };
        }
        renderEpcResultArea();
    }

    async function selectEpcAddress(lmkKey){
        epcUi.status = 'loading';
        renderEpcResultArea();
        const json = await postJson('epc_get', { lmk_key: lmkKey });
        if(!json.success){
            epcUi = { status:'error', message: json.message || 'Could not load certificate.', addresses:[] };
            renderEpcResultArea();
            return;
        }
        state.property.epc = json.data;
        state.property.ageBand = json.data.age_band;
        if(!state.roomsManuallyEdited){
            state.rooms = generateRoomsFromEpc(json.data);
        }
        epcUi = { status:'selected', message:'', addresses:[] };
        renderPropertyStep(); // full re-render so the age-band dropdown picks up the new value
    }

    function clearEpc(){
        state.property.epc = null;
        epcUi = { status:'idle', message:'', addresses:[] };
        renderPropertyStep();
    }

    function renderEpcResultArea(){
        const el = document.getElementById('epcResultArea');
        if(!el) return;
        const p = state.property;

        if(p.epc){
            const u = p.epc.u_values;
            const ageBandLabel = (AGE_BANDS.find(b=>b.id===p.epc.age_band)||{}).label || p.epc.age_band;
            const srcLabel = s => s === 'epc_description' ? 'from EPC' : 'age-band default';
            const fab = p.epc.fabric_detail || {};
            const er = p.epc.epc_rating || {};
            const co2 = p.epc.co2_emissions_tonnes_per_year || {};
            const cons = p.epc.energy_consumption_kwh_per_m2 || {};
            const cost = p.epc.annual_running_cost_gbp || { heating:{}, hot_water:{}, lighting:{} };
            const totalCurrent = (cost.heating.current||0) + (cost.hot_water.current||0) + (cost.lighting.current||0);
            const totalPotential = (cost.heating.potential||0) + (cost.hot_water.potential||0) + (cost.lighting.potential||0);
            const heat = p.epc.heating_system || {};
            const improvements = p.epc.suggested_improvements || [];

            const fabricRows = (label, entries) => (entries||[]).map(en => `
          <div class="raw-desc-row"><div class="rd-k">${label}</div><div class="rd-v">${escapeHtml(en.description || 'No data')} <span class="src" style="margin-left:4px;">(U ${en.u_value.toFixed(2)}, ${srcLabel(en.source)}${en.epc_efficiency_rating!==null?', EPC rating '+en.epc_efficiency_rating+'/5':''})</span></div></div>`).join('');

            el.innerHTML = `
      <div class="epc-box">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
          <div>
            <span class="badge ${p.epc.is_valid?'good':'warn'}">${p.epc.is_valid?'Valid EPC':'Expired EPC'}</span>
            ${er.current_band?`<span class="badge">Current EPC ${escapeHtml(er.current_band)} (${fmtNum(er.current_score)})</span>`:''}
            ${er.potential_band?`<span class="badge good">Potential EPC ${escapeHtml(er.potential_band)} (${fmtNum(er.potential_score)})</span>`:''}
            <div style="font-size:12.5px;color:var(--text-muted);margin-top:6px;">
              Lodged ${p.epc.lodgement_date} · ${p.epc.property_type||'Property type n/a'} · ${ageBandLabel}
              ${p.epc.total_floor_area?' · '+p.epc.total_floor_area+'m² total floor area':''}
              ${p.epc.habitable_rooms?' · '+p.epc.habitable_rooms+' habitable rooms':''}
            </div>
          </div>
          <button class="btn btn-ghost btn-sm" onclick="clearEpc()">Use different address</button>
        </div>
        <div class="uval-grid">
          <div class="uval-cell"><div class="k">Wall U</div><div class="v">${u.wall.value.toFixed(2)}</div><div class="src">${srcLabel(u.wall.source)}</div></div>
          <div class="uval-cell"><div class="k">Roof U</div><div class="v">${u.roof.value.toFixed(2)}</div><div class="src">${srcLabel(u.roof.source)}</div></div>
          <div class="uval-cell"><div class="k">Floor U</div><div class="v">${u.floor.value.toFixed(2)}</div><div class="src">${srcLabel(u.floor.source)}</div></div>
          <div class="uval-cell"><div class="k">Window U</div><div class="v">${u.window.value.toFixed(2)}</div><div class="src">${srcLabel(u.window.source)}</div></div>
        </div>
        <div class="raw-desc-list">
          ${fabricRows('Wall', fab.wall)}
          ${fabricRows('Roof', fab.roof)}
          ${fabricRows('Floor', fab.floor)}
          ${fabricRows('Window', fab.window)}
        </div>
        <h3 style="font-size:13.5px;margin:18px 0 6px;">Existing heating &amp; hot water</h3>
        <div class="raw-desc-list">
          ${heat.main && heat.main.length ? `<div class="raw-desc-row"><div class="rd-k">Heating</div><div class="rd-v">${escapeHtml(heat.main.join('; '))}</div></div>` : ''}
          ${heat.secondary && heat.secondary !== 'None' ? `<div class="raw-desc-row"><div class="rd-k">Secondary</div><div class="rd-v">${escapeHtml(heat.secondary)}</div></div>` : ''}
          ${heat.hot_water ? `<div class="raw-desc-row"><div class="rd-k">Hot water</div><div class="rd-v">${escapeHtml(heat.hot_water)}</div></div>` : ''}
          ${heat.controls && heat.controls.length ? `<div class="raw-desc-row"><div class="rd-k">Controls</div><div class="rd-v">${escapeHtml(heat.controls.join('; '))}</div></div>` : ''}
          ${heat.lighting ? `<div class="raw-desc-row"><div class="rd-k">Lighting</div><div class="rd-v">${escapeHtml(heat.lighting)}</div></div>` : ''}
        </div>
        <h3 style="font-size:13.5px;margin:18px 0 6px;">Estimated annual running costs (from EPC)</h3>
        <div class="cost-row header-row"><div class="label"></div><div class="value">Current</div><div class="value">Potential</div></div>
        <div class="cost-row"><div class="label">Heating</div><div class="value">${fmtGbp(cost.heating.current)}</div><div class="value">${fmtGbp(cost.heating.potential)}</div></div>
        <div class="cost-row"><div class="label">Hot water</div><div class="value">${fmtGbp(cost.hot_water.current)}</div><div class="value">${fmtGbp(cost.hot_water.potential)}</div></div>
        <div class="cost-row"><div class="label">Lighting</div><div class="value">${fmtGbp(cost.lighting.current)}</div><div class="value">${fmtGbp(cost.lighting.potential)}</div></div>
        <div class="cost-row net"><div class="label">Total</div><div class="value">${fmtGbp(totalCurrent)}</div><div class="value">${fmtGbp(totalPotential)}</div></div>
        <div style="font-size:12.5px;color:var(--text-muted);margin-top:10px;">
          CO₂: ${fmtNum(co2.current,' t/yr')} current, ${fmtNum(co2.potential,' t/yr')} potential ·
          Energy consumption: ${fmtNum(cons.current,' kWh/m²/yr')} current, ${fmtNum(cons.potential,' kWh/m²/yr')} potential
        </div>
        ${improvements.length ? `
        <h3 style="font-size:13.5px;margin:18px 0 6px;">EPC's suggested improvements</h3>
        <div class="raw-desc-list">
          ${improvements.map(imp => `<div class="raw-desc-row"><div class="rd-k">#${imp.sequence} (${escapeHtml(imp.type_code||'')})</div><div class="rd-v">Saves ~${fmtGbp(imp.typical_saving_gbp)}/yr · cost ${escapeHtml(imp.indicative_cost||'n/a')} · new EPC score ${fmtNum(imp.resulting_epc_score)}</div></div>`).join('')}
        </div>` : ''}
        ${!p.epc.is_valid ? '<div class="error-text">This certificate is over 10 years old — treat these figures as a rough guide only.</div>' : ''}
      </div>
    `;
            return;
        }

        if(epcUi.status === 'loading'){ el.innerHTML = `<div style="margin-top:12px;font-size:13px;color:var(--text-muted);"><span class="spinner"></span>Checking…</div>`; return; }
        if(epcUi.status === 'error'){ el.innerHTML = `<div class="error-text">${escapeHtml(epcUi.message)}</div>`; return; }
        if(epcUi.status === 'list'){
            el.innerHTML = `<div class="epc-box">
      <div style="font-size:12.5px;font-weight:600;margin-bottom:4px;">Select your address:</div>
      ${epcUi.addresses.map(a => `
        <div class="epc-address-row">
          <div>${escapeHtml(a.address)}<div style="color:var(--text-muted);font-size:11.5px;">${a.lodgement_date} ${a.is_valid?'':'· expired'}</div></div>
          <button class="btn btn-ghost btn-sm" onclick="selectEpcAddress('${a.lmk_key}')">Use this</button>
        </div>`).join('')}
    </div>`;
            return;
        }
        el.innerHTML = '';
    }

    /* ---------------- STEP 1: ROOMS (unchanged logic) ---------------- */
    function renderRoomsStep(){
        const seededFromEpc = state.property.epc && !state.roomsManuallyEdited;
        let banner = '';
        if(seededFromEpc){
            banner = `<div class="caveat" style="margin-bottom:16px;">
      <strong>Rooms below are a starting point</strong>, generated from your EPC's room count (${state.property.epc.habitable_rooms || 'estimated'}) and total floor area (${state.property.epc.total_floor_area ? state.property.epc.total_floor_area+'m²' : 'estimated'}) split evenly. EPCs don't include room-by-room geometry, so dimensions here are a rough square-footprint guess — adjust each room's length/width and add external wall/window areas for an accurate result.
    </div>`;
        }
        let html = state.rooms.map((r,i)=>roomCardHtml(r,i)).join('');
        html += `<button class="add-room-btn" onclick="addRoom()">+ Add another room</button>`;
        document.getElementById('stepContent').innerHTML = `
    ${banner}
    ${html}
    <div class="btn-row">
      <button class="btn btn-ghost" onclick="goStep(0)">← Back</button>
      <button class="btn btn-primary" id="calcBtn" onclick="goStep(2)">Calculate heat loss →</button>
    </div>`;
        wireRoomInputs();
        updateCalcButtonState();
    }
    function roomCardHtml(r,i){
        const area = (parseFloat(r.length)||0) * (parseFloat(r.width)||0);
        const vol = area * (parseFloat(r.height)||0);
        return `
  <div class="room-card ${r.collapsed?'collapsed':''}" data-id="${r.id}">
    <div class="room-head" onclick="toggleRoom(${r.id})">
      <div class="room-head-left">
        <div class="room-num">${i+1}</div>
        <div><span class="room-name">${escapeHtml(r.name) || 'Room '+(i+1)}</span>
        <span class="room-meta">${area? area.toFixed(1)+'m² · '+vol.toFixed(1)+'m³':''}</span></div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;">
        <button class="btn-danger" onclick="event.stopPropagation();removeRoom(${r.id})" ${state.rooms.length<=1?'disabled style="opacity:.3;cursor:not-allowed;"':''}>Remove</button>
        <span class="chevron">▾</span>
      </div>
    </div>
    <div class="room-body">
      <div class="field-grid">
        <div class="field"><label>Room name</label><input type="text" data-room="${r.id}" data-field="name" value="${escapeHtml(r.name)}"></div>
        <div class="field"><label>Room type</label><select data-room="${r.id}" data-field="type">${Object.entries(ROOM_TYPES).map(([k,v])=>`<option value="${k}" ${k===r.type?'selected':''}>${v.label}</option>`).join('')}</select></div>
        <div class="field"><label>Length (m)</label><input type="number" step="0.1" min="0" data-room="${r.id}" data-field="length" value="${r.length}"></div>
        <div class="field"><label>Width (m)</label><input type="number" step="0.1" min="0" data-room="${r.id}" data-field="width" value="${r.width}"></div>
        <div class="field"><label>Ceiling height (m)</label><input type="number" step="0.1" min="0" data-room="${r.id}" data-field="height" value="${r.height}"></div>
        <div class="field"><label>External wall area <span class="hint">(m², gross)</span></label><input type="number" step="0.1" min="0" data-room="${r.id}" data-field="extWallArea" value="${r.extWallArea}"></div>
        <div class="field"><label>Window area <span class="hint">(m²)</span></label><input type="number" step="0.1" min="0" data-room="${r.id}" data-field="windowArea" value="${r.windowArea}"></div>
        <div class="field"><label>&nbsp;</label><div class="checkrow"><input type="checkbox" data-room="${r.id}" data-field="hasDoor" ${r.hasDoor?'checked':''}> Has external door</div></div>
        <div class="field"><label>&nbsp;</label><div class="checkrow"><input type="checkbox" data-room="${r.id}" data-field="groundFloor" ${r.groundFloor?'checked':''}> Ground floor (exposed floor)</div></div>
        <div class="field"><label>&nbsp;</label><div class="checkrow"><input type="checkbox" data-room="${r.id}" data-field="topFloor" ${r.topFloor?'checked':''}> Top floor (roof above)</div></div>
      </div>
      <div class="live-calc">Floor area: ${area? area.toFixed(1):'—'} m² · Volume: ${vol? vol.toFixed(1):'—'} m³</div>
    </div>
  </div>`;
    }
    function wireRoomInputs(){
        document.querySelectorAll('[data-room]').forEach(el=>{
            const handler = () => {
                state.roomsManuallyEdited = true;
                const room = state.rooms.find(r=>r.id === parseInt(el.dataset.room));
                const field = el.dataset.field;
                if(el.type === 'checkbox') room[field] = el.checked;
                else if(el.type === 'number') room[field] = el.value === '' ? '' : parseFloat(el.value);
                else room[field] = el.value;
                const card = document.querySelector(`.room-card[data-id="${room.id}"]`);
                const area = (parseFloat(room.length)||0) * (parseFloat(room.width)||0);
                const vol = area * (parseFloat(room.height)||0);
                card.querySelector('.room-name').textContent = room.name || 'Room';
                card.querySelector('.room-meta').textContent = area ? area.toFixed(1)+'m² · '+vol.toFixed(1)+'m³' : '';
                card.querySelector('.live-calc').textContent = `Floor area: ${area? area.toFixed(1):'—'} m² · Volume: ${vol? vol.toFixed(1):'—'} m³`;
                updateCalcButtonState();
            };
            el.addEventListener(el.type === 'checkbox' || el.tagName === 'SELECT' ? 'change' : 'input', handler);
        });
    }
    function updateCalcButtonState(){
        const btn = document.getElementById('calcBtn');
        if(!btn) return;
        const valid = state.rooms.every(r => r.length && r.width && r.height);
        btn.disabled = !valid;
    }
    function toggleRoom(id){
        const r = state.rooms.find(r=>r.id===id);
        r.collapsed = !r.collapsed;
        document.querySelector(`.room-card[data-id="${id}"]`).classList.toggle('collapsed');
    }
    function addRoom(){ state.roomsManuallyEdited = true; state.rooms.push(newRoom()); renderRoomsStep(); }
    function removeRoom(id){ if(state.rooms.length<=1) return; state.roomsManuallyEdited = true; state.rooms = state.rooms.filter(r=>r.id!==id); renderRoomsStep(); }

    /* ---------------- CALCULATION ---------------- */
    function getDesignTemp(postcode, altitude){
        const clean = (postcode||'').toUpperCase().replace(/\s/g,'');
        const match = clean.match(/^([A-Z]{1,2})/);
        let base = DEFAULT_DESIGN_TEMP;
        if(match){
            const two = match[1], one = match[1][0];
            if(POSTCODE_DESIGN_TEMP[two] !== undefined) base = POSTCODE_DESIGN_TEMP[two];
            else if(POSTCODE_DESIGN_TEMP[one] !== undefined) base = POSTCODE_DESIGN_TEMP[one];
        }
        return base - ((altitude||0)/100) * 0.6;
    }

    // Returns the effective U-value set for this property, in priority order:
    // EPC-derived (if a valid EPC is loaded) > manually-picked construction
    // type (same categories an EPC assessor records) > age-band default.
    function getEffectiveUValues(){
        const ageBand = state.property.ageBand;
        const ageDefaults = AGE_BAND_U_VALUES[ageBand];
        const epc = state.property.epc;
        if(epc && epc.is_valid){
            return {
                wall: epc.u_values.wall.value, roof: epc.u_values.roof.value,
                floor: epc.u_values.floor.value, window: epc.u_values.window.value,
                door: ageDefaults.door, // EPC has no door descriptor — always age-band
                source: 'epc'
            };
        }
        const c = state.property.construction || {};
        const pick = el => (c[el] && DESCRIPTION_RULES[el] && DESCRIPTION_RULES[el][c[el]] !== undefined) ? DESCRIPTION_RULES[el][c[el]] : ageDefaults[el];
        const anyManual = ['wall','roof','floor','window'].some(el => c[el]);
        return {
            wall: pick('wall'), roof: pick('roof'), floor: pick('floor'), window: pick('window'),
            door: ageDefaults.door, // no manual door-type category — always age-band, same as the EPC path
            source: anyManual ? 'manual_construction' : 'age_band'
        };
    }

    function calculateRoom(room, externalTemp, uValues){
        const area = (parseFloat(room.length)||0) * (parseFloat(room.width)||0);
        const vol = area * (parseFloat(room.height)||0);
        const roomType = ROOM_TYPES[room.type];
        const deltaT = Math.max(roomType.internalTemp - externalTemp, 0);

        const windowArea = parseFloat(room.windowArea)||0;
        const doorArea = room.hasDoor ? (parseFloat(room.doorArea)||1.85) : 0;
        const grossWall = parseFloat(room.extWallArea)||0;
        const netWall = Math.max(grossWall - windowArea - doorArea, 0);

        let fabricLoss = netWall*uValues.wall*deltaT + windowArea*uValues.window*deltaT + doorArea*uValues.door*deltaT;
        if(room.topFloor) fabricLoss += area * uValues.roof * deltaT;
        if(room.groundFloor) fabricLoss += area * uValues.floor * deltaT;

        const ach = roomType.baseAch * INFILTRATION_MULT[state.property.ageBand];
        const ventLoss = 0.33 * ach * vol * deltaT;

        return { area, vol, deltaT, fabricLoss, ventLoss, total: fabricLoss + ventLoss };
    }

    // Sizing margin for DHW recovery, defrost cycles and general
    // oversizing headroom — a rough allowance, not an MCS calculation.
    const SIZING_MARGIN = 1.15;

    function matchEquipment(totalKw){
        const demandKw = totalKw * SIZING_MARGIN;
        const fits = EQUIPMENT_CATALOG.filter(m => m.capacity_kw >= demandKw).sort((a,b)=>a.capacity_kw-b.capacity_kw);
        if(fits.length === 0){
            // demand exceeds the whole catalogue — flag for bespoke design rather than silently mis-sizing
            const largest = [...EQUIPMENT_CATALOG].sort((a,b)=>b.capacity_kw-a.capacity_kw)[0];
            return { demandKw, oversized: true, primary: largest, alternatives: [] };
        }
        const smallestCapacity = fits[0].capacity_kw;
        const atThatCapacity = fits.filter(m => m.capacity_kw === smallestCapacity);
        return { demandKw, oversized:false, primary: atThatCapacity[0], alternatives: atThatCapacity.slice(1) };
    }

    function estimateCost(matched){
        const eqLow = matched.primary.price_gbp;
        const eqHigh = matched.alternatives.length ? Math.max(eqLow, ...matched.alternatives.map(a=>a.price_gbp)) : eqLow;
        const c = INSTALL_COST_BANDS;
        const totalLow = eqLow + c.cylinder.low + c.labour.low + c.extras.low;
        const totalHigh = eqHigh + c.cylinder.high + c.labour.high + c.extras.high;
        const netLow = Math.max(totalLow - BUS_GRANT_GBP, 0);
        const netHigh = Math.max(totalHigh - BUS_GRANT_GBP, 0);
        return { eqLow, eqHigh, totalLow, totalHigh, netLow, netHigh };
    }

    /* ---------------- STEP 2: RESULTS ---------------- */
    function renderResultsStep(){
        const externalTemp = getDesignTemp(state.property.postcode, state.property.altitude);
        const uValues = getEffectiveUValues();
        const results = state.rooms.map(r => ({room:r, calc: calculateRoom(r, externalTemp, uValues)}));
        const totalW = results.reduce((s,r)=>s+r.calc.total,0);
        const totalKw = totalW/1000;
        const maxRoom = Math.max(...results.map(r=>r.calc.total), 1);

        const rows = results.map(r => `
    <div class="room-result-row">
      <div class="rr-name">${escapeHtml(r.room.name)}<div style="color:var(--text-muted);font-weight:400;font-size:11.5px;">${ROOM_TYPES[r.room.type].label}</div></div>
      <div class="rr-value">${r.calc.deltaT.toFixed(1)}°C ΔT</div>
      <div class="rr-value">${Math.round(r.calc.total)} W</div>
      <div class="heat-bar-track"><div class="heat-bar-fill" style="width:${(r.calc.total/maxRoom*100).toFixed(0)}%"></div></div>
    </div>`).join('');

        const matched = matchEquipment(totalKw);
        const cost = estimateCost(matched);
        const gbp = n => '£' + Math.round(n).toLocaleString('en-GB');

        const altHtml = matched.alternatives.map(m => `
    <div class="equip-card">
      <div><div class="equip-name">${m.brand} ${m.model}</div><div class="equip-meta">${m.capacity_kw}kW · SCOP ${m.scop}</div></div>
      <div class="equip-price">${gbp(m.price_gbp)}</div>
    </div>`).join('');

        document.getElementById('stepContent').innerHTML = `
    <div class="summary-card">
      <div class="summary-inner">
        <div class="summary-eyebrow">Indicative design heat loss</div>
        <div class="summary-value">${totalKw.toFixed(2)}<span>kW</span></div>
        <div class="summary-sub">External design temp: ${externalTemp.toFixed(1)}°C · U-values from ${uValues.source === 'epc' ? 'your EPC' : uValues.source === 'manual_construction' ? 'your construction selections' : 'age-band defaults'} · ${state.rooms.length} room${state.rooms.length!==1?'s':''}</div>
      </div>
    </div>

    <div class="card">
      <h3 style="margin-bottom:4px;">Recommended system</h3>
      <p style="font-size:12.5px;color:var(--text-muted);margin:0 0 4px;">Sized to ${matched.demandKw.toFixed(2)}kW (design heat loss + ${Math.round((SIZING_MARGIN-1)*100)}% margin for hot water and defrost)</p>
      ${matched.oversized ? `<div class="error-text" style="margin-top:8px;">This property's demand exceeds our standard catalogue — needs a bespoke multi-unit or commercial-grade design. Showing the largest single unit as a reference point only.</div>` : ''}
      <div class="equip-card primary">
        <div><div class="equip-name">${matched.primary.brand} ${matched.primary.model}</div><div class="equip-meta">${matched.primary.capacity_kw}kW capacity · SCOP ${matched.primary.scop}</div></div>
        <div class="equip-price">${gbp(matched.primary.price_gbp)}</div>
      </div>
      ${altHtml}
    </div>

    <div class="card">
      <h3 style="margin-bottom:14px;">Estimated installation cost</h3>
      <div class="cost-row"><div class="label">Heat pump unit</div><div class="value">${cost.eqLow===cost.eqHigh?gbp(cost.eqLow):gbp(cost.eqLow)+' – '+gbp(cost.eqHigh)}</div></div>
      <div class="cost-row"><div class="label">Hot water cylinder</div><div class="value">${gbp(INSTALL_COST_BANDS.cylinder.low)} – ${gbp(INSTALL_COST_BANDS.cylinder.high)}</div></div>
      <div class="cost-row"><div class="label">Electrical, pipework &amp; commissioning</div><div class="value">${gbp(INSTALL_COST_BANDS.extras.low)} – ${gbp(INSTALL_COST_BANDS.extras.high)}</div></div>
      <div class="cost-row"><div class="label">Installation labour</div><div class="value">${gbp(INSTALL_COST_BANDS.labour.low)} – ${gbp(INSTALL_COST_BANDS.labour.high)}</div></div>
      <div class="cost-row"><div class="label">Total installed cost</div><div class="value">${gbp(cost.totalLow)} – ${gbp(cost.totalHigh)}</div></div>
      <div class="cost-row grant"><div class="label">Boiler Upgrade Scheme grant</div><div class="value">– ${gbp(BUS_GRANT_GBP)}</div></div>
      <div class="cost-row net"><div class="label">Estimated net cost</div><div class="value">${gbp(cost.netLow)} – ${gbp(cost.netHigh)}</div></div>
      <div class="caveat" style="margin-top:14px;">Equipment and labour figures are indicative catalogue/market rates, not a quote. BUS grant eligibility depends on survey findings and MCS certification of the final design — confirmed at the in-home visit.</div>
    </div>

    <div class="card">
      <h3 style="margin-bottom:14px;">Room-by-room breakdown</h3>
      <div class="room-result-row header-row"><div>Room</div><div>Design ΔT</div><div>Heat loss</div><div>Relative share</div></div>
      ${rows}
    </div>
    <div class="caveat"><strong>This is an indicative estimate</strong>, not a certified MCS heat loss calculation. The figure needed for Boiler Upgrade Scheme eligibility and final system design is produced by an MCS-certified assessor at an in-home survey.</div>
    <div class="btn-row">
      <button class="btn btn-ghost" onclick="goStep(1)">← Edit rooms</button>
      <div style="display:flex;gap:10px;">
        <button class="btn btn-ghost" onclick="downloadCsv()">Download CSV</button>
        <button class="btn btn-primary" onclick="alert('Wire this button to your lead-capture / booking flow.')">Book a design survey</button>
      </div>
    </div>`;
        window._lastResults = { externalTemp, results, totalKw, uValues, matched, cost };
    }

    function downloadCsv(){
        const { externalTemp, results, totalKw, uValues, matched, cost } = window._lastResults;
        let csv = 'Room,Type,Area (m2),Volume (m3),Design dT (C),Fabric loss (W),Ventilation loss (W),Total loss (W)\n';
        results.forEach(r=>{
            csv += [r.room.name, ROOM_TYPES[r.room.type].label, r.calc.area.toFixed(1), r.calc.vol.toFixed(1),
                r.calc.deltaT.toFixed(1), Math.round(r.calc.fabricLoss), Math.round(r.calc.ventLoss), Math.round(r.calc.total)].join(',') + '\n';
        });
        csv += `\nTotal design heat loss (kW),${totalKw.toFixed(2)}\nExternal design temp (C),${externalTemp.toFixed(1)}\n`;
        csv += `U-value source,${uValues.source}\nPostcode,${state.property.postcode}\nAge band,${state.property.ageBand}\n`;
        csv += `\nRecommended unit,${matched.primary.brand} ${matched.primary.model}\nUnit capacity (kW),${matched.primary.capacity_kw}\nUnit SCOP,${matched.primary.scop}\n`;
        csv += `Total installed cost (low-high GBP),${Math.round(cost.totalLow)}-${Math.round(cost.totalHigh)}\n`;
        csv += `BUS grant (GBP),${BUS_GRANT_GBP}\nEstimated net cost (low-high GBP),${Math.round(cost.netLow)}-${Math.round(cost.netHigh)}\n`;
        const blob = new Blob([csv], {type:'text/csv'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a'); a.href = url; a.download = 'wunergy-heat-loss-estimate.csv'; a.click();
        URL.revokeObjectURL(url);
    }

    function goStep(n){
        if(n === 2 && !state.rooms.every(r => r.length && r.width && r.height)){ updateCalcButtonState(); return; }
        state.step = n; render(); window.scrollTo({top:0, behavior:'smooth'});
    }

    render();
</script>
</body>
</html>