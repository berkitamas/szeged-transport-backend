<?php
/*
 * Copyright 2016-2019 mWave Networks Kft.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace App\Http\Controllers;


use function array_fill;
use function array_push;
use function explode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use function implode;
use function response;
use function strtolower;
use function var_dump;

class StopController extends Controller {
    public function getStopById(Request $request, $id) {
        $stop = DB::table('stops')->where("id", $id)->first();
        if (empty($stop)) {
            return response("{}", 404);
        }
        $routes = DB::select(<<<STMT
SELECT DISTINCT routes.*
FROM routes, trips
WHERE routes.id = trips.route_id AND
trips.id IN (
	SELECT trip_id
	FROM stop_times
	WHERE stop_id IN (
		SELECT id FROM stops WHERE name = (SELECT name FROM stops WHERE id = ?)
	)
)
AND occasional != ?
ORDER BY type, short_name;
STMT
        , [$id, ($request->has("occasional")?-1:1)]);
        $stop->routes = $routes;
        return response()->json($stop);
    }

    public function searchStop(Request $request) {
        //SELECT MIN(id) AS id, name FROM stop_times, stops WHERE trip_id IN (SELECT id FROM trips WHERE route_id IN (1, 7)) AND stop_id = stops.id AND LOWER(name) LIKE '%lz%' GROUP BY(name) ORDER BY name;
        $fields = [];
        $tables = ['stops'];
        $wheres = [];
        if ($request->has('routes')) {
            $routes = explode(',', $request->input('routes'));
            foreach ($routes as $route) {
                $wheres[] = "id IN (SELECT id FROM stops WHERE name IN (SELECT name FROM stops, stop_times, trips WHERE stop_id = stops.id AND trip_id = trips.id AND route_id = ?))";
                $fields[] = $route;
            }
        }
        if ($request->has('name')) {
            array_push($wheres, "LOWER(name) LIKE ?");
            array_push($fields, '%'.strtolower(trim($request->input('name'))).'%');
        }
        $query = "SELECT MIN(id) AS id, name FROM " . implode(", ", $tables) . ((!empty($wheres))?' WHERE '.implode(' AND ', $wheres):'') . ' GROUP BY(name) ORDER BY name';
        return response()->json(DB::select($query, $fields));

    }

    public function listRoutesByStopIdAndTimestamp(Request $request, $id) {
        if ($request->has("time")) {
            $routes = DB::select(<<<STMT
SELECT routes.id, agency_id, short_name, long_name, `desc`, type, occasional, trip_id, CAST(arrival_time AS time) AS arrival_time, CAST(departure_time AS TIME) AS departure_time, stop_sequence
FROM routes, trips, (
    SELECT trip_id, arrival_time, departure_time, stop_sequence
    FROM stop_times
    WHERE stop_id IN (
            SELECT id 
            FROM stops 
            WHERE name = (SELECT name FROM stops WHERE id = ?)
        ) AND 
        (UNIX_TIMESTAMP(arrival_time) - ? + UNIX_TIMESTAMP(DATE(FROM_UNIXTIME(?))) ) <= 5*60 AND
        (UNIX_TIMESTAMP(arrival_time) - ? + UNIX_TIMESTAMP(DATE(FROM_UNIXTIME(?))) ) >= 0
    ) AS stop_times
WHERE trips.id = stop_times.trip_id AND
routes.id = trips.route_id AND
service_id IN (SELECT service_id
    FROM calendar, (SELECT ABS(DAYOFWEEK(FROM_UNIXTIME(?)) - 1) AS dow) AS DOW
    WHERE (FROM_UNIXTIME(?) BETWEEN start_date AND end_date) AND (
            (DOW.dow = 1 AND monday = 1) OR 
            (DOW.dow = 2 AND tuesday = 1) OR 
            (DOW.dow = 3 AND wednesday = 1) OR 
            (DOW.dow = 4 AND thursday = 1) OR 
            (DOW.dow = 5 AND friday = 1) OR
            (DOW.dow = 6 AND saturday = 1) OR
            (DOW.dow = 7 AND sunday = 1)
    )
)
ORDER BY arrival_time;
STMT
                , [
                    $id,
                    (integer) $request->get("time"),
                    (integer) $request->get("time"),
                    (integer) $request->get("time"),
                    (integer) $request->get("time"),
                    (integer) $request->get("time"),
                    (integer) $request->get("time")
                ]);
            foreach ($routes as &$route) {
                $trip_id = $route->trip_id;
                $stop_seq = $route->stop_sequence;
                unset($route->trip_id);
                unset($route->stop_sequence);
                $next_stop = DB::select("SELECT * FROM stops WHERE id = (SELECT stop_id FROM stop_times WHERE trip_id = ? AND stop_sequence = ?)", [$trip_id, $stop_seq + 1]);
                $route->next_stop = (!empty($next_stop)) ? $next_stop[0] : null;
                $route->trip_start = DB::select("SELECT * FROM stops WHERE id = (SELECT stop_id FROM stop_times WHERE trip_id = ? AND stop_sequence = 1)", [$trip_id])[0];
                $route->trip_end = DB::select("SELECT * FROM stops WHERE id = (SELECT stop_id FROM stop_times WHERE trip_id = ? ORDER BY stop_sequence DESC LIMIT 1)", [$trip_id])[0];
            }
            return response()->json($routes);
        }
        return response("{}",400);
    }
}