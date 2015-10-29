<?php
require 'Slim/Slim.php';
include_once 'Connection.php';

\Slim\Slim::registerAutoloader();

$app = new \Slim\Slim();


// GET route
$app->get(
    '/',
    function () {
        echo "De volgende params kunnen worden gebruikt: <br>
        <table>
        <tr><th>Params</th><th>Description</th></tr>

        <tr><td><b> /station/:stationnumber </b></td><td> Alle info van dat stationnummer </td></tr>

        <tr><td><b> /station/all </b></td><td> Alle info van alle stations </td></tr>

        <tr><td><b> /..... </b></td><td> More soon </td></tr>

        <tr><td><b> /..... </b></td><td> First query requirement: The measurements around Moscow(200km radius, from the centre of Moscow. Moscow local time).<br> 
And only if the temperature is higher than 18 degrees celsius (query, max response time: 2 minutes) </td></tr>

        <tr><td><b> /top10 </b></td><td> Second query requirement: With every Friday  22:00 – 00:00 will this query be accessed<br>
Also about top 10 peak temperature in 24h per longitude, <br>
only for Moscow (indicate which country the data is from) (max response time: 10 seconds)<br>
This should be available from Monday till Saturday 6:00 ~ 8:00 AM Moscow localtime (GMT +3) </td></tr>

        <tr><td><b> /rainfall/:stationnumber </b></td><td> Third query requirement: Rainfall in the world of any weatherstation of the current day
(from the current time till 00:00, going back) </td></tr>
        ";
    }
);

$app->group('/station', function () use ($app) {
    $app->get(
        '/:station',
        function ($station) {
            $conn = Connection::getInstance();

            if($station == 'all'){
                $statement = $conn->db->prepare("SELECT * FROM stations");
                $statement->execute();
            }else {
                $statement = $conn->db->prepare("SELECT * FROM stations WHERE stn = :stn");
                $statement->execute(array(':stn' => "$station"));
            }
            $results = $statement->fetchAll(PDO::FETCH_ASSOC);

            $json = json_encode($results);
            echo $json;
        }
    );

});

$app->group('/measurement', function () use ($app) {
    //moet nog
});



/*
First: 
The measurements around Moscow(200km radius, from the centre of Moscow. Moscow local time). 
And only if the temperature is higher than 18 degrees celsius (query, max response time: 2 minutes)

Second:
With every Friday  22:00 – 00:00 will this query be accessed
Also about top 10 peak temperature in 24h per longitude, 
only for Moscow (indicate which country the data is from) (max response time: 10 seconds)
This should be available from Monday till Saturday 6:00 ~ 8:00 AM Moscow localtime (GMT +3)
*/
$app->get(
    '/top10',
    function(){
        $conn = Connection::getInstance();
        $statement = $conn->db->prepare("
            SELECT s.country, m.temp, s.name, s.country, s.longitude, m.date, m.time 
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
            LIMIT 10");
        $statement->execute();
        $results = $statement->fetchAll(PDO::FETCH_ASSOC);
        
        $json = json_encode($results);
        echo $json;
    }
);

/*
Third:
Rainfall in the world of any weatherstation of the current day
(from the current time till 00:00, going back)
*/
$app->get(
    '/rainfall/:station',
    function ($station) {
        $conn = Connection::getInstance();
        $statement = $conn->db->prepare("SELECT prcp FROM measurements WHERE stn = :stn AND date = :date");
        $statement->execute( array(':stn' => "$station", ':date' => date("Y-m-d")) );
        $results = $statement->fetchAll(PDO::FETCH_ASSOC);
        
        $json = json_encode($results);
        echo $json;
    }
);







$app->run();