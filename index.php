<?php

// a random string that you need to pass to this script if you want
// to update some sensor values
$config = json_decode(file_get_contents('config.json'), true);
$keys = $config['api_keys'];

date_default_timezone_set('America/Sao_Paulo');

switch(get_controller()) {

    case 'sensor':

        switch(get_action()) {

            case 'set':

                // Until now only use case #1 of docs/update_use_cases.svg
                // is supported. The problem is the addressing of specific
                // instances of a sensor array of the same type

                if( !isset($_POST['key']) && !isset($_GET['key']) )
                {
                    header('HTTP/1.0 403 Forbidden');
                    die('No key provided');
                }

                $sensors = '';
                $client_key = '';
                switch($_SERVER['REQUEST_METHOD'])
                {
                    case 'POST':

                        $client_key = $_POST['key'];
                        $sensors = urldecode($_POST['sensors']);
                        break;

                    case 'GET':

                        $client_key = $_GET['key'];
                        $sensors = urldecode($_GET['sensors']);
                        break;
                }

                if(!in_array($client_key, $keys))
                {
                    header('HTTP/1.0 403 Forbidden');
                    die('Wrong key');
                }

                // convert the json to an associative array
                $sensors = json_decode($sensors, true);

                if (isset($sensors['state']['open'])) {
                    $sensors['state']['trigger_person'] = array_search($client_key, $keys);
                    $sensors['state']['lastchange'] = time();
                }

                if(! is_null($sensors)){
                    save_sensors($sensors);
                    notify($sensors);
                } else
                    die('Invalid JSON: '. $sensors);

                break;

            default:
        }

        break;

    default:

        //switch(@$_GET['format']) {
        switch(get_action()) {

            case 'json':

                output_json();
                break;

            case 'bash':

                output_bash();
                break;

            default:

                output_html();
        }
}

/**
 * Creates a file for each single sensor so that the probability
 * of race conditions is reduced while updating multiple sensor
 * values independently.
 *
 * @param $sensors associative array with the sensor values
 * @param string $path a path dynamically populated out of the sensor array keys (internal usage only)
 */
function save_sensors($sensors, $path = "") {

    foreach($sensors as $key => $value) {

        $delimiter = '';
        if(!empty($path))
            $delimiter = ".";

        // We mustn't override $path here because of sensor arrays
        // whose values are no arrays and thus no recursion will take
        // place. If we overrode the path, for three instances of
        // temperature sensors we would create files such as
        //
        //  temperature.0.value
        //  temperature.0.1.value
        //  temperature.0.1.2.value
        //
        $new_path = $path . $delimiter . $key;

        if(is_array($value)) {
            save_sensors($value, $new_path);
        } else {

            $type = gettype($value);

            // instead of determing the type of what the sensor
            // measurement unit delivers us we should get the type
            // directly from the specs by using (& caching regularly)
            // http://spaceapi.net/specs/0.13
            file_put_contents('data/' . $new_path, "$type:$value");
        }
    }
}

###########################################
# SEND NOTIFICATION EMAIL

function notify($sensors){

    if($sensors['state']['open'])
        $status = 'open';
    else
        $status = 'closed';

    $subject = json_decode(file_get_contents('spaceapi.json'), true)['space'].' status updated: '.$status;

    $message = 'Status updated to "'.$status.'" at '.date(DATE_RFC2822).' by '.$sensors['state']['trigger_person'].' from '.$_SERVER['REMOTE_ADDR'].'('.$_SERVER['HTTP_USER_AGENT'].')';

    mail("update@status.laboratoriohacker.org", $subject, $message, 'From: Status - LabHacker <status@laboratoriohacker.org>');

    $telegram = json_decode(file_get_contents('config.json'), true)['telegram'];

    file_get_contents("https://jaconda.im/api/v1/endpoint/groups/".$telegram['group']."/messages?token=".$telegram['token']."&text=".urlencode("LabHacker ".$message));

}

###########################################
# BASIC ROUTING HELPERS

function get_common_path($path1, $path2) {

    // in the future the function should accept an array of paths,
    // as of now if more than two paths are provided, this will fail
    // because any common path elements which exist in the other arrays
    // are considered 'common' but only those elements that next to the
    // other without hole in the array are the real common elements.
    $paths = array($path1, $path2);

    $exploded_paths = array();
    foreach ($paths as $path) {
        $exploded_paths[] = explode('/', trim($path, '/'));
    }

    // initialize the common paths array
    $common_path = $exploded_paths[0];

    foreach ($exploded_paths as $path) {
        $common_path = array_intersect($path, $common_path);
    }

    return '/' . join('/', $common_path);
}

function get_controller() {

    return get_router_segment(0);
}

function get_action() {

    return get_router_segment(1);
}

function get_router_segment($index) {

    $segments = explode('/', get_request_uri());
    return @$segments[$index];
}

function get_request_uri() {

    $request_uri = $_SERVER['REQUEST_URI'];
    //$request_uri = preg_replace('|^/|', '', $request_uri);

    if($request_uri === '') {
        $request_uri = @$_SERVER['REDIRECT_URL'];
        //$request_uri = preg_replace('|^/|', '', $request_uri);
    }

    // in the following we must check if DOCUMENT_ROOT is part of
    // REQUEST_URI, because this is not always the case, especially
    // if the endpoint scripts are located in a place which is aliased
    // in a VHOST config, see https://github.com/SpaceApi/endpoint-scripts/issues/4

    if (strpos(__DIR__, $_SERVER['DOCUMENT_ROOT']) >= 0) {
        $route = str_replace(
            __DIR__,
            '',
            $_SERVER['DOCUMENT_ROOT'] . $request_uri
        );
    } else {
        $common_path = get_common_path($request_uri, __DIR__);

        $not_common = explode($common_path, $request_uri);
        $not_common = array_reverse($not_common);
        $not_common = $not_common[0];

        $route = $not_common;
    }

    $route = preg_replace('|^/|', '', $route);

    return $route;
}


###########################################
# OUTPUT HELPERS

function output_html() {

    global $config;

    header('Content-type: text/html; charset=UTF-8');

    $template = file_get_contents('template.html');

    $protocol = ($_SERVER['SERVER_PORT'] === 443 ||
        @$_SERVER['HTTPS'] === 'on' ||
        @$_SERVER['REDIRECT_HTTPS'] === 'on') ? 'https' : 'http';

    $base_url = "$protocol://"
        . $_SERVER['HTTP_HOST']
        . $_SERVER['REQUEST_URI'];

    // substitute template variables
    $html = str_replace('{{ baseurl }}', $base_url, $template);

    // remove comments
    $html = preg_replace('/{#.*#}/', '', $html);

    echo $html;
}

function output_json() {

    header('Content-type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-cache');

    $json = file_get_contents('spaceapi.json');

    // we need an associative array later and no object
    $spaceapi = json_decode($json, true);

    // iterate over all sensor files and merge them with the static
    // spaceapi.json
    $sensor_data = glob('data/*');

    if  (!empty($sensor_data)) {
        foreach(glob('data/*') as $file) {

            $file_content = file_get_contents($file);

            list($type, $value) = explode(':', $file_content);
            settype($value, $type);

            // here we take the file name of a data file (in the data dir)
            // and create a list of indices which will be to address the
            // corresponding field in the spaceapi.json template.
            $array_path = basename($file);
            $array_path = explode('.', $array_path);

            // get a reference of the spaceapi, see the explanation later
            $sub_array = &$spaceapi;

            $do_write_value = true;
            foreach($array_path as $path_segment) {

                // here we check if the sensor (or what we pushed to the
                // endpoint scripts) is defined in the template, if it's
                // not we will skip the value. The skip is done via a flag
                // since we cannot use continue because we'd need to
                // tell the outer loop to continue but not the current one
                // we're currently in
                if(!array_key_exists($path_segment, $sub_array))
                {
                    $do_write_value = false;
                    break;
                }

                // get the sub array of the spaceapi data structure (taken
                // from the template) while we walk along the path according
                // the file name (sliced into indices)
                $sub_array = &$sub_array[$path_segment];
            }

            // finally merge the value of the data file into the spaceapi
            // data structure
            if($do_write_value)
                $sub_array = $value;
        }
    }

    echo json_encode($spaceapi);
}

function output_bash() {

    header('Content-type: text/plain');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-cache');

    $json = file_get_contents('spaceapi.json');

    $spaceapi = json_decode($json, true);

    $sensor_data = glob('data/*');

    if  (!empty($sensor_data)) {
        foreach(glob('data/*') as $file) {

            $file_content = file_get_contents($file);

            list($type, $value) = explode(':', $file_content);
            settype($value, $type);

            // here we take the file name of a data file (in the data dir)
            // and create a list of indices which will be to address the
            // corresponding field in the spaceapi.json template.
            $array_path = basename($file);
            $array_path = explode('.', $array_path);

            // get a reference of the spaceapi, see the explanation later
            $sub_array = &$spaceapi;

            $do_write_value = true;
            foreach($array_path as $path_segment) {

                // here we check if the sensor (or what we pushed to the
                // endpoint scripts) is defined in the template, if it's
                // not we will skip the value. The skip is done via a flag
                // since we cannot use continue because we'd need to
                // tell the outer loop to continue but not the current one
                // we're currently in
                if(!array_key_exists($path_segment, $sub_array))
                {
                    $do_write_value = false;
                    break;
                }

                // get the sub array of the spaceapi data structure (taken
                // from the template) while we walk along the path according
                // the file name (sliced into indices)
                $sub_array = &$sub_array[$path_segment];
            }

            // finally merge the value of the data file into the spaceapi
            // data structure
            if($do_write_value)
                $sub_array = $value;
        }
    }

    $message = $spaceapi['space'].' is ';

    if ($spaceapi['state']['open'])
        $message .= '\e[42m\e[1;37m open \e[m';
    else
        $message .= "\e[41m\e[1;37m closed \e[m";

    $message .= " (updated by ".$spaceapi['state']['trigger_person']." at ".date(DATE_RFC2822, $spaceapi['state']['lastchange']).")";

    echo $message;

}


###########################################
# BASIC DEBUGGING HELPERS

function dump($mixed, $is_html = false)
{
    if($is_html)
        echo "<pre>" . htmlspecialchars(print_r($mixed, true)) . "</pre>";
    else
        echo "<pre>" . print_r($mixed, true) . "</pre>";
}

function dumpx($mixed)
{
    dump($mixed);
    exit();
}
