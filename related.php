<?php
ini_set("display_errors","On");
require_once("main-api.php");
require('RestClient.php');

$tasks_buffer = array();
$limit = 10;
$depth = 1;
        $envPath = dirname(dirname(__DIR__)) . '/env/data-for-seo-env.php';

        if (!file_exists($envPath)) 
        {
            die('DataForSEO env file missing: ' . $envPath);
            return null;
        }

        $env = require $envPath;

        //$body = getInput();
        $body = array("solar install guide","home solar benefits");
        //var_dump($body); //die();

        $post_array = array();
        foreach($body as $kwd)
        {
            $post_array[] = array(
                "keyword" => $kwd,
                "language_name" => "English",
                "location_code" => 2840,
                "include_serp_info"=> false,
                "include_seed_keyword"=> false,
                "include_clickstream_data"=>false,
                "replace_with_core_keyword"=>true,
                "filters" => [
                                    ["keyword_info.search_volume", ">", 20], 
                                    "AND",
                                    ["keyword_info.search_volume", "<=", 10000],     
                                    "AND",
                                    ["keyword_info.cpc", ">", 0], 
                                    "AND",
                                    ["keyword_info.keyword_length", ">=", 2],
                                    "AND",
                                    ["keyword_info.keyword_length", "<=", 4],
                                    "AND",
                                    ["keyword_info.competition_level", "not_regex", "null"] 
                            ],
                "depth"=>$depth,
                "ignore_synonyms"=>false,
                "limit" => $limit
            );
        }

        //var_dump($post_array);

        $client = NULL;

        try 
        {
            // Instead of 'login' and 'password' use your credentials from https://app.dataforseo.com/api-access
            $client = new RestClient($env['api_url'], null, $env['username'], $env['password']);
            //var_dump($client);

        }
        catch (RestClientException $e) 
        {
                echo "n";
                print "HTTP code: {$e->getHttpCode()}n";
                print "Error code: {$e->getCode()}n";
                print "Message: {$e->getMessage()}n";
                print  $e->getTraceAsString();
                echo "n";
                exit();
        }

       if (count($post_array) > 0) 
        {
            try {
                // Example for SERP Google Organic Task POST endpoint
                $result = $client->post("/v3/dataforseo_labs/google/related_keywords/live", $post_array);

                //print_r($result);
                    if ($result['status_code'] == 20000) 
                    {
                            echo "\nSuccessfully created multiple tasks.\n";

                            foreach ($result['tasks'] as $task) {
                                //echo "Task ID: " . $task['id'] . "\n";
                                var_dump($task);
                            }
                    } 
                    else 
                    {
                        echo "\nError creating tasks: " . $result['status_message'] . "\n";
                    }
                } 
                catch (RestClientException $e) 
                {
                            echo "\n";
                            print "HTTP code: {$e->getHttpCode()}\n";
                            print "Error code: {$e->getCode()}\n";
                            print "Message: {$e->getMessage()}\n";
                            print $e->getTraceAsString();
                            echo "\n";
                }
        }
?>
