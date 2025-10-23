<?php
/*
Another way to implement to get best keywords
1. Seed Keyword to /v3/dataforseo_labs/google/keyword_suggestions/live API get Get keyword suggestions from keyword suggestions API => input seed keyword and filters, try exact_match=false and true

competition_level not null
order_by: ["-keyword_info.search_volume"]

2. Input each keyword from step 2 of keyword suggestions to related keywords api (/v3/dataforseo_labs/google/related_keywords/live), keep depth 2, and limit 50-60.
3. get metrics on all related keywords sv>=50 and sv<=10000, cpc>=0.5
4. combine list of all keywords.
*/

ini_set('max_execution_time', 30000); 
ini_set('memory_limit', '-1');
ini_set("display_errors","On");

require_once("main-api.php");
require_once("get-metrics.php");
require_once("get-keyword-suggestions.php");

require_once("get-ai-search-volume.php");

$login = '';
$password = '';

$depth = 2;
$limit = 100;
        $filters = [
        ["keyword_data.keyword_info.search_volume",">=",50],
        "and",
        ["keyword_data.keyword_info.search_volume","<=",10000],
        "and",
        ["keyword_data.keyword_info.cpc",">",0]];

$post_data = array();

$related_keywords_with_metrics = array();
$related_keywords_with_without_metrics = array();

$seed_keyword = "solar installation";

$keyword_suggestions_with_metrics = getKeywordSuggestions($seed_keyword, 150);

foreach($keyword_suggestions_with_metrics as $k) $related_keywords_with_metrics[]=$k;

//echo"<pre>-----------------</pre>";
//var_dump($keyword_suggestions_with_metrics); die();

//sendResponseJSON($keyword_suggestions_with_metrics); die();

$i=0;

/*
$keyword_suggestions_with_metrics = [
  "solar installation",
  "solar system basics",
  "home solar benefits",
  "install solar steps",
];
*/
foreach($keyword_suggestions_with_metrics as $kwd)
{
    //if($i>=20) break; // for testing only

            $post_data[] = json_encode([
                [
                    "keyword" => $kwd,
                    "language_name" => "English",
                    "location_code" => 2840,
                    "include_serp_info"=> false,
                    "include_seed_keyword"=> false,
                    "include_clickstream_data"=>false,
                    "replace_with_core_keyword"=>true,
                    //"filters" => $filters,
                    "depth"=>$depth,
                    "ignore_synonyms"=>false,
                    "limit" => $limit
                ]
            ]);
    //$i++;
}

//var_dump($post_data); die();

$url ='https://api.dataforseo.com/v3/dataforseo_labs/google/related_keywords/live';

// Initialize a cURL multi handle
$mh = curl_multi_init();

// Array to store individual cURL handles
$ch_array = [];

// Create individual cURL handles and add them to the multi handle
foreach ($post_data as $key => $post_item) 
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_item);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode("$login:$password")
    ]);
    curl_multi_add_handle($mh, $ch);
    $ch_array[$key] = $ch;
}

// Execute the cURL requests in parallel
$running = null;
do {
    curl_multi_exec($mh, $running);
} while ($running > 0);

// Get the responses and close the handles
$responses = [];
foreach ($ch_array as $key => $ch) {
    $responses[$key] = curl_multi_getcontent($ch);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}

// Close the multi handle
curl_multi_close($mh);

// Process the responses
foreach ($responses as $key => $response) 
{
    //echo "Response from URL " . ($key + 1) . ":\n";
    $data = json_decode($response);

    $keywords = $data->tasks[0]->result[0]->items ?? null;

    //var_dump($keywords); die();

    if (!is_array($keywords)) { 
        continue;
    }
        foreach($keywords as $k) 
        {
            $kd = $k->keyword_data ?? null;
            if (!$kd) { continue; } // skip malformed entries

            $intent = $k->keyword_data->search_intent_info->main_intent ?? 'not available';

            $competition = $k->keyword_data->keyword_info->competition ?? 0;
            $competition_level = strtoupper($k->keyword_data->keyword_info->competition_level ?? "UNKNOWN");
            // If competition = 0, fallback from level
            if ($competition == 0) {
                switch ($competition_level) {
                    case "LOW":    $competition = 0.3; break;
                    case "MEDIUM": $competition = 0.6; break;
                    case "HIGH":   $competition = 0.9; break;
                    default:       $competition = 0.5; break; // UNKNOWN
                }
            }

            // Now — reverse logic: if level is UNKNOWN, derive from competition numeric value
            if ($competition_level === "UNKNOWN" || $competition_level === "" || $competition_level === null) {
                if ($competition < 0.33) {
                    $competition_level = "LOW";
                } elseif ($competition < 0.66) {
                    $competition_level = "MEDIUM";
                } else {
                    $competition_level = "HIGH";
                }
            }

            // Safety clamp
            $competition = max(0, min(1, (float)$competition));

            $cpc = $k->keyword_data->keyword_info->cpc ?? 0;

            $keyword_difficulty = $k->keyword_data->keyword_properties->keyword_difficulty ?? "UNKNOWN";
            $search_volume = $k->keyword_data->keyword_info->search_volume;
            $rank = 1;

            $computed_score = compute_score($search_volume, $cpc, $competition );
            $estimated_traffic = estimateTraffic($search_volume, $rank, $keyword_difficulty);

            $related_keywords_with_metrics[] =array(
                'keyword'=> $k->keyword_data->keyword, 
                'competition' => $competition, 
                'competition_level'=> $competition_level,
                'is_related'        => true,
                'cpc' => $cpc,
                'search_volume' => $search_volume,
                'estimated_traffic' => $estimated_traffic,
                'keyword_score' => $computed_score,
                "keyword_difficulty" => $keyword_difficulty,
                "search_intent" => $intent,
            );
            
            if (!empty($k->related_keywords) && is_array($k->related_keywords)) {
                    foreach ($k->related_keywords as $related_kw) {
                        $related_keywords_with_without_metrics[] = $related_kw;
                    }
            }
        }
}

$other_kwd_metrics = getKeywordMetrics($related_keywords_with_without_metrics,true);

// Extract keywords

// this code needs fix

function attach_ai_search_volume_by_keyword(array $ai_search_volumes, array $keyword_data): array 
{
    // 1. Create a lookup map for ai_search_volume using the keyword as the key
    $ai_volume_map = [];
    foreach ($ai_search_volumes as $item) {
        $keyword = $item['keyword'] ?? null;
        if ($keyword) {
            $ai_volume_map[$keyword] = $item['ai_search_volume'] ?? null;
        }
    }

    $merged_array = [];

    // 2. Iterate through the main data array and add the volume from the map
    foreach ($keyword_data as $data) {
        $keyword = $data['keyword'] ?? null;
        
        // Retrieve the volume from the map, using 0 if the keyword doesn't exist
        $ai_volume = $ai_volume_map[$keyword] ?? 0; // Use 0 or null as a default if keyword is missing
        
        // Add the 'ai_search_volume' to the current element
        $data['ai_search_volume'] = $ai_volume;
        
        // Add the modified data to the new array
        $merged_array[] = $data;
    }
    
    // Optional: You can remove the count warning if you use this keyword-based merging,
    // as it gracefully handles missing keywords or extra keywords in either array.
    
    return $merged_array;
}

function remove_duplicate_keywords(array $array): array {
    $unique_items = [];
    
    foreach ($array as $item) {
        $keyword = $item['keyword'] ?? null;
        
        // Use the 'keyword' as the array key. 
        // Duplicate keywords will overwrite the previous entry.
        if ($keyword !== null) {
            $unique_items[$keyword] = $item;
        }
    }
    
    // Use array_values() to reset the array keys from keywords back to 0, 1, 2...
    return array_values($unique_items);
}

function get_non_present_keywords_in_metrics_array(array $seed_keywords, array $keywords_with_metrics): array
{
    // 1. Extract the 'keyword' column from the metrics array
    $metric_keywords = array_column($keywords_with_metrics, 'keyword');
    
    // 2. Find the difference between the two arrays
    $not_present = array_diff($seed_keywords, $metric_keywords);
    
    return $not_present;
}

$ai_search_volume_kwd_list = array();
foreach($related_keywords_with_metrics as $k)
    $ai_search_volume_kwd_list[] = $k["keyword"];

$ai_search_keyword_metrics = getAI_SearchVolume_for_Keywords($ai_search_volume_kwd_list);

$merged_data = attach_ai_search_volume_by_keyword($ai_search_keyword_metrics, $related_keywords_with_metrics);

$unique_data = remove_duplicate_keywords($merged_data);

usort($unique_data, function($a, $b) {
            $scoreA = $a['keyword_score'] ?? 0;
            $scoreB = $b['keyword_score'] ?? 0;
            return $scoreB <=> $scoreA;
});

// Save this JSON under project.

sendResponseJSON( $unique_data );

?>
