<?php
require 'Slim/Slim.php';
include_once 'Connection.php';
use League\Csv\Reader;
use League\Csv\Writer;
require 'vendor/autoload.php';

date_default_timezone_set('Europe/Moscow');

\Slim\Slim::registerAutoloader();

$app = new \Slim\Slim();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

$app->add(new \Slim\Middleware\SessionCookie(array(
    'expires' => '20 minutes',
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
        echo "De volgende params kunnen worden gebruikt: <br>
        <table>
        <tr><th>Params</th><th>Description</th></tr>

        <tr><td><b> /station/:stationnumber </b></td><td> Alle info van dat stationnummer </td></tr>

        <tr><td><b> /station/all </b></td><td> Alle info van alle stations </td></tr>

        <tr><td><b> /moscow/all </b></td><td> Alle info over alle stations in een radius van 200km rondom moskou </td></tr>

        <tr><td><b> /moscow/temp </b></td><td> First query requirement: The measurements around Moscow(200km radius, from the centre of Moscow. Moscow local time).<br>
And only if the temperature is higher than 18 degrees celsius (query, max response time: 2 minutes) </td></tr>

         <tr><td><b> /moscow/temp?export=true </b></td><td> download csv van nu tot max 3 maanden geleden. </td></tr>

        <tr><td><b> /top10 </b></td><td> Second query requirement: With every Friday  22:00 – 00:00 will this query be accessed<br>
Also about top 10 peak temperature in 24h per longitude, <br>
only for Moscow (indicate which country the data is from) (max response time: 10 seconds)<br>
This should be available from Monday till Saturday 6:00 ~ 8:00 AM Moscow localtime (GMT +3) </td></tr>

        <tr><td><b> /top10?export=true </b></td><td> download csv met max 10 temps van vandaag </td></tr>

        <tr><td><b> /rainfall/:stationnumber </b></td><td> Third query requirement: Rainfall in the world of any weatherstation of the current day
(from the current time till 00:00, going back) </td></tr>

        <tr><td><b> /rainfall/:stationnumber?export=true </b></td><td> download csv van vandaag. </td></tr>
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
            $app->response()->status(401);
            $app->response->headers->set('Content-Type', 'application/json');
            $error = array("error"=> array("text"=>"Not authorized!"));
            echo json_encode($error);
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

//LOGIN
$app->post(
    '/login',
    function () use ($app) {
        $email = $_POST['email'];
        $password = $_POST['password'];

        $conn = Connection::getInstance();
        $statement = $conn->db->prepare("
            SELECT *
            FROM users
            WHERE email = :email
            AND password = :password
            ");
        $statement->execute( array(':email' => "$email", ':password' => "$password") );
        $results = $statement->fetchAll();

        if( count($results) <> 1 ){
            $error = array("error"=> array("text"=>"Username or Password does not exist, is not filled in, or is not correct"));
            $app->response->headers->set('Content-Type', 'application/json');
            echo json_encode($error);
        }else if( count($results) == 1){
            $_SESSION['loggedin'] = true;
            $success = array("success"=> array("text"=>"Log in successful"));
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
                $statement = $conn->db->prepare("SELECT stn as 'id', name as 'title', country, latitude, longitude FROM stations");
                $statement->execute();
            }else {
                $statement = $conn->db->prepare("SELECT stn as 'id', name as 'title', country latitude, longitude FROM stations WHERE stn = :stn");
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
        '/temp',
        authenticateUser(),
        function() use ($app){
            $export = $_GET['export'];

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
            $moskvaStation = $stmt->fetchAll();

            $stns = [];
            foreach($allStations as $station){
                $afstand = distance($moskvaStation[0]['latitude'], $moskvaStation[0]['longitude'],$station['latitude'],$station['longitude']);
                if($afstand <= 200){
                    $stns[] = $station['stn'];
                }
            }

            $stationnummers = implode(",",$stns);

            if($export == "true"){
                $query = "
                    SELECT s.name, m.stn, m.date, m.time, m.temp
                    FROM measurements AS m
                    JOIN stations AS s ON s.stn = m.stn
                    WHERE m.stn
                    IN ($stationnummers)
                    AND m.date >= now()-interval 3 month
                    ORDER BY m.date, s.name, m.time DESC
                    ";
                $headerArray = array('Name', 'Station', 'Date', 'Time', 'Temp celsius');
                $filename = "moscow-temps ".  date('Y-m-d',(strtotime ( '-3 month' , strtotime ( date("Y-m-d")) ) )) . " to ". date("Y-m-d");

                exportCSV($query, $headerArray, $filename);

            } else { //HAALT Alle measurements van de laatste 24h op
                $statement2 = $conn->db->prepare("
                    SELECT s.name, m.stn, m.temp, m.date, m.time
                    FROM measurements AS m
                    JOIN stations AS s ON s.stn = m.stn
                    WHERE m.stn
                    IN ($stationnummers)
                    AND m.temp > 18
                    AND date >= now() - INTERVAL 1 DAY
                    ");
                $statement2->execute();
                $results2 = $statement2->fetchALL();

                $app->response->headers->set('Content-Type', 'application/json');
                $json = json_encode($results2);
                echo $json;
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
            $moskvaStation = $stmt->fetchAll();

            $stns = [];
            foreach($allStations as $station){
                $afstand = distance($moskvaStation[0]['latitude'], $moskvaStation[0]['longitude'],$station['latitude'],$station['longitude']);
                if($afstand <= 200){
                    $stns[] = $station['stn'];
                }
            }

            $stationnummers = implode(",",$stns);

            $statement2 = $conn->db->prepare("
                SELECT *
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
        $export = $_GET['export'];

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
            AND date >= now() - INTERVAL 1 DAY
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
        $export = $_GET['export'];
        $conn = Connection::getInstance();
        $query = "
            SELECT time, prcp
            FROM measurements
            WHERE stn = $station
            AND date = '".date("Y-m-d")."'";

        if($export == "true"){// TODO ------------------------------------------------------------
            $headerArray = array('Time', 'Prcp');
            $filename = "Rainfall stn-$station ". date("Y-m-d");

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