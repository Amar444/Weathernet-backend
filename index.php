<?php
require 'Slim/Slim.php';
include_once 'Connection.php';
use League\Csv\Reader;
use League\Csv\Writer;
require 'vendor/autoload.php';

date_default_timezone_set('UTC');

\Slim\Slim::registerAutoloader();

$app = new \Slim\Slim();

//Disable debugging
$app->config('debug', false);

header('Access-Control-Allow-Origin: http://localhost:9000');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

$app->add(new \Slim\Middleware\SessionCookie(array(
    'expires' => '60 minutes',
    'domain' => null,
    'secure' => false,
    'httponly' => false,
    'name' => 'slim_session',
    'secret' => 'CHANGE_ME',
    'cipher' => MCRYPT_RIJNDAEL_256,
    'cipher_mode' => MCRYPT_MODE_CBC
)));

// GET route
$app->get(
    '/',
    function () {
        echo "The following parameters can be used: <br>
        <table>
        <tr><th>Params</th><th>Description</th></tr>

        <tr><td><b> /station/:stationnumber </b></td><td> All info of the given stationnumber </td></tr>

        <tr><td><b> /station/all </b></td><td> All info of all the stations </td></tr>

        <tr><td><b> /moscow/all </b></td><td> All info about all the stations in a radius of 200km around Moscow </td></tr>

        <tr><td><b> /moscow/temp </b></td><td> <b>First query requirement:</b> The measurements around Moscow(200km radius, from the centre of Moscow. Moscow local time).<br>
And only if the temperature is higher than 18 degrees celsius (query, max response time: 2 minutes) </td></tr>

        <tr><td><b> /moscow/temp?export=true </b></td><td> Download CSV from now till 3 months ago. </td></tr>

        <tr><td><b> /top10 </b></td><td> <b>Second query requirement:</b>
The top 10 peak temperature in 24h per longitude (same longitude of Moscow), <br>
(indicate which country the data is from) (max response time: 10 seconds)<br>
This should be available from Monday till Saturday 6:00 ~ 8:00 AM Moscow localtime (GMT +3) </td></tr>

        <tr><td><b> /top10?export=true </b></td><td> download csv met max 10 temps van vandaag </td></tr>

        <tr><td><b> /rainfall/:stationnumber </b></td><td> Third query requirement: Rainfall in the world of any weatherstation of the current day
(from the current time till 00:00, going back) </td></tr>

        <tr><td><b> /rainfall/:stationnumber?export=true </b></td><td> download csv van vandaag. </td></tr>
        </table>
        ";

        echo "
            <form action='/login' method='post'>
              email:<br>
              <input type='text' name='email'>
              <br>
              password:<br>
              <input type='password' name='password'>
              <button type='submit' name='submit'>Login</button>
            </form>
        ";
    }
);

function distance($lat1, $lon1, $lat2, $lon2) {
    $pi80 = M_PI / 180;
    $lat1 *= $pi80;
    $lon1 *= $pi80;
    $lat2 *= $pi80;
    $lon2 *= $pi80;

    $r = 6372.797; // mean radius of Earth in km
    $dlat = $lat2 - $lat1;
    $dlon = $lon2 - $lon1;
    $a = sin($dlat / 2) * sin($dlat / 2) + cos($lat1) * cos($lat2) * sin($dlon / 2) * sin($dlon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    $km = $r * $c;

    return $km;
}

function authenticateUser(){
    return function(){
        if(!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] == false){
            $app = \Slim\Slim::getInstance();
            $app->response->headers->set('Content-Type', 'application/json');
            $error = json_encode(array("error"=> array("text"=>"Not authorized!")));
            $app->halt(401, $error);
            die;
        }
    };
};

function exportCSV($query, $headerArray, $filename){
    $conn = Connection::getInstance();
    $statement = $conn->db->prepare($query);
    $statement->setFetchMode(PDO::FETCH_ASSOC);
    $statement->execute();

    $csv = Writer::createFromFileObject(new SplTempFileObject());
    $csv->insertOne($headerArray);
    $csv->insertAll($statement);
    $csv->output($filename.'.csv');
    die;

}

// Options to enable CORS on /+
$app->options('/(:name+)', function() use ($app) {
});
//LOGIN
$app->post(
    '/login',
    function () use ($app) {

        // check of we proberen te posten "inloggen vanaf zelfde site"
        // zo niet dan halen we de credentials uit request body
        if(isset($_POST['email']) && isset($_POST['password'])){
            $email = $_POST['email'];
            $password = $_POST['password'];
        } else{
            $credentials = json_decode($app->request()->getBody());
            $email = $credentials->email;
            $password = $credentials->password;
        }

        $conn = Connection::getInstance();
        $statement = $conn->db->prepare("
            SELECT email, first_name, last_name
            FROM users
            WHERE email = :email
            AND password = :password
            ");
        $statement->execute( array(':email' => "$email", ':password' => "$password") );
        $results = $statement->fetchAll();

        if( count($results) <> 1 ){
            $error = array("error"=> array("text"=>"Username or Password does not exist, is not filled in, or is not correct"));
            $app->response->headers->set('Content-Type', 'application/json');
            $app->halt(401, json_encode($error));
        }else if( count($results) == 1){
            $_SESSION['loggedin'] = true;
            $success = array("success"=> array("text"=>"Log in successful"),"data" => json_encode($results));
            $app->response->headers->set('Content-Type', 'application/json');
            echo json_encode($success);
        }
    }
);

//LOGOUT
$app->get(
    '/logout',
    function () use ($app) {
        if( !isset($_SESSION['loggedin']) ){
            $error = array("error"=> array("text"=>"There is nobody logged in!"));
            $app->response->headers->set('Content-Type', 'application/json');
            echo json_encode($error);
        }else {
            $_SESSION['loggedin'] = false;
            $success = array("success"=> array("text"=>"You are now logged out!"));
            $app->response->headers->set('Content-Type', 'application/json');
            echo json_encode($success);
        }
    }
);


//***********************************************************
////**************** AUTHORIZED FUNCTIONS! //***********************************************************
//***********************************************************

$app->group('/station', function () use ($app) {
    $app->get(
        '/:station',
        authenticateUser(),
        function ($station) use ($app) {
            $conn = Connection::getInstance();

            if($station == 'all'){
                $statement = $conn->db->prepare("SELECT stn, name as 'title', country, latitude, longitude FROM stations");
                $statement->execute();
            }else {
                $statement = $conn->db->prepare("SELECT stn, name as 'title', country, latitude, longitude FROM stations WHERE stn = :stn");
                $statement->execute(array(':stn' => "$station"));
            }
            $results = $statement->fetchAll(PDO::FETCH_ASSOC);

            $app->response->headers->set('Content-Type', 'application/json');
            $json = json_encode($results);
            echo $json;
        }
    );
});


/*
First:
The measurements around Moscow(200km radius, from the centre of Moscow. Moscow local time).
And only if the temperature is higher than 18 degrees celsius (query, max response time: 2 minutes)
*/
$app->group('/moscow', function () use ($app) {
    $app->get(
        '/temp/:temp',
        authenticateUser(),
        function($temp) use ($app){
            // wel checken of die geset is anders werkt dit niet
            $export = isset($_GET['export']) ? $_GET['export'] : false;

            $conn = Connection::getInstance();
            $statement = $conn->db->prepare("
                SELECT stn, latitude, longitude
                FROM stations
                ");
            $stmt = $conn->db->prepare("
                SELECT latitude, longitude
                FROM stations
                WHERE name = 'MOSKVA'
                ");

            $statement->execute();
            $stmt->execute();

            $allStations = $statement->fetchAll();
            //$moskvaStation = $stmt->fetchAll();
            $moskvaLatitude = "55.751244";
            $moskvaLongitude = "37.618423";


            $stns = [];
            foreach($allStations as $station){
                $afstand = distance($moskvaLatitude, $moskvaLongitude ,$station['latitude'],$station['longitude']);
                if($afstand <= 200){
                    $stns[] = $station['stn'];
                }
            }

            $stationnummers = implode(",",$stns);

            // wut waarom "true" en niet true - roelof 4-11-2015
            if($export == "true"){
                $query = "
                    SELECT s.name, m.stn, m.date, m.time, m.temp
                    FROM measurements AS m
                    JOIN stations AS s ON s.stn = m.stn
                    WHERE m.stn
                    IN ($stationnummers)
                    AND m.temp > ".$temp."
                    AND m.date >= now()-interval 3 month
                    ORDER BY s.name DESC, m.date ASC, m.time ASC
                    ";
                $headerArray = array('Name', 'Station', 'Date', 'Time', 'Temp celsius');
                $filename = "moscow-temps ".  date('Y-m-d',(strtotime ( '-3 month' , strtotime ( date("Y-m-d")) ) )) . " to ". date("Y-m-d");

                exportCSV($query, $headerArray, $filename);

            } else {
                // HAALT Alle measurements van de laatste 3 maanden op
                // temp > 10 anders kunnen we niet testen
                // TODO VERANDER TERUG NAAR 18 WANT 10 IS GEEN REUQUIREMENT - roelof 4-11-2015
                $statement2 = $conn->db->prepare("
                    SELECT s.name, m.stn, m.temp, m.date, m.time
                    FROM measurements AS m
                    JOIN stations AS s ON s.stn = m.stn
                    WHERE m.stn
                    IN ($stationnummers)
                    AND m.temp > :temp
                    AND date >= now() - INTERVAL 3 month
                    ORDER BY date ASC, time ASC
                    ");
                $statement2->execute(array('temp' => $temp));
                $results2 = $statement2->fetchALL();

                $labels = [];
                $response = [];
                $stations = [];
                $series = [];
                // fill stations
                foreach($results2 as $r){
                    if(!in_array($r['stn'], $stations)){
                        $stations[] = $r['stn'];
                        $series[] = $r['name'];
                    }
                }
                // fill labels and response
                foreach($results2 as $r){
                    if(!in_array($r['date'].'_'.$r['time'], $labels)){
                        $labels[] = $r['date'].'_'.$r['time'];
                    }
                    // Vul de bekende value van stn die we weten
                    $response[$r['stn']][] = $r['temp'];
                    foreach($stations as $stn){
                        if($stn != $r['stn']){
                            $c = isset($response[$stn]) ? count($response[$stn]) : 0;
                            if($c > 0){
                                $response[$stn][] = $response[$stn][$c-1];
                            } else {
                                $response[$stn][] = null;
                            }
                        }
                    }
                }
                $res['series'] = $series;
                $res['labels'] = $labels;
                $res['data'] = $response;

                $app->response->headers->set('Content-Type', 'application/json');
                echo json_encode($res);
            }
        }
    );


    $app->get(
        '/all',
        authenticateUser(),
        function() use ($app){
            $conn = Connection::getInstance();
            $statement = $conn->db->prepare("
                SELECT stn, latitude, longitude
                FROM stations
                ");
            $stmt = $conn->db->prepare("
                SELECT latitude, longitude
                FROM stations
                WHERE name = 'MOSKVA'
                ");

            $statement->execute();
            $stmt->execute();

            $allStations = $statement->fetchAll();
            //$moskvaStation = $stmt->fetchAll();
            $moskvaLatitude = "55.751244";
            $moskvaLongitude = "37.618423";

            $stns = [];
            foreach($allStations as $station){
                $afstand = distance($moskvaLatitude, $moskvaLongitude ,$station['latitude'],$station['longitude']);
                if($afstand <= 200){
                    $stns[] = $station['stn'];
                }
            }

            $stationnummers = implode(",",$stns);

            $statement2 = $conn->db->prepare("
                SELECT stn, name as 'title', country, longitude, latitude
                FROM stations
                WHERE stn
                IN ($stationnummers)
                ");
            $statement2->execute();
            $statement2->execute();
            $results2 = $statement2->fetchALL();

            $app->response->headers->set('Content-Type', 'application/json');
            $json = json_encode($results2);
            echo $json;
        }
    );
});

/*
Second:
With every Friday  22:00 – 00:00 will this query be accessed
Also about top 10 peak temperature in 24h per longitude,
only for Moscow (indicate which country the data is from) (max response time: 10 seconds)
This should be available from Monday till Saturday 6:00 ~ 8:00 AM Moscow localtime (GMT +3)
*/
$app->get(
    '/top10',
    authenticateUser(),
    function() use($app){
        // check of het is gezet anders krijgen we 500 errors
        $export = isset($_GET['export']) ? $_GET['export'] : false;

        $conn = Connection::getInstance();

        $query = "
            SELECT s.country, s.name, s.longitude, m.date, m.time, m.temp
            FROM measurements AS m
            JOIN stations AS s ON m.stn = s.stn
            WHERE s.longitude LIKE CONCAT (
                (SELECT TRUNCATE(longitude,0)
                FROM stations
                WHERE name = 'MOSKVA'
                ),'%'
            )
            AND UNIX_TIMESTAMP(CONCAT(date,' ',time)) >= UNIX_TIMESTAMP(UTC_TIMESTAMP()-interval 24 hour)
            group by s.country
            ORDER BY temp DESC
            LIMIT 10
            ";



        $statement = $conn->db->prepare($query);

        if($export == "true"){// TODO ------------------------------------------------------------
            $headerArray = array('Country', 'Name', 'Longitude', 'Date', 'Time', 'Temp celsius');
            $filename = "top10-temps-longitude-moscow ". date("Y-m-d");

            exportCSV($query, $headerArray, $filename);

        } else {
            $statement->execute();
            $results = $statement->fetchAll(PDO::FETCH_ASSOC);

            $app->response->headers->set('Content-Type', 'application/json');
            $json = json_encode($results);
            echo $json;
        }
    }
);

/*
Third:
Rainfall in the world of any weatherstation of the current day
(from the current time till 00:00, going back)
*/
$app->get(
    '/rainfall/:station',
    authenticateUser(),
    function ($station) use ($app){
        $export = isset($_GET['export']) ? $_GET['export'] : false;

        $conn = Connection::getInstance();
        $query = "
            SELECT time, prcp
            FROM measurements
            WHERE stn = $station
            AND date = '".date("Y-m-d")."'
            ORDER BY time ASC";


        if($export == "true"){
            $stmt = $conn->db->prepare("Select name from stations where stn = :stn");
            $stmt->execute([':stn' => $station]);
            $res = $stmt->fetchAll();
            $name = $station;
            if(count($res) > 0){
                $name = $res[0]['name'];
            }

            $headerArray = array('time', 'Prcp');
            $filename = "Rainfall_{$name}_". date("Y-m-d");

            exportCSV($query, $headerArray, $filename);

        } else {
            $statement = $conn->db->prepare($query);
            $statement->execute();
            $results = $statement->fetchAll(PDO::FETCH_ASSOC);

            $app->response->headers->set('Content-Type', 'application/json');
            $json = json_encode($results);
            echo $json;
        }
    }
);



$app->run();