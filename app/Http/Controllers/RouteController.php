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

use function date_parse;
use function explode;
use i;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use function implode;
use function MongoDB\BSON\toJSON;
use function response;
use function sort;
use function strtolower;
use function trim;
use function var_dump;

class RouteController extends Controller
{

    public function list(Request $request) {
        if ($request->has("occasional")) {
            return DB::table('routes')->select("id", "short_name", "long_name", "type", "occasional")->get();
        } else {
            return DB::table('routes')->select("id", "short_name", "long_name", "type", "occasional")->where("occasional", '=', 0)->get();
        }
        // id, short_name, long_name, type FROM routes%sORDER BY type, short_name;",
        //            ($request->has("occasional")) ? " " :" WHERE occasional=0 "
    }

    public function searchRoute(Request $request) {
        //from_time, to_time, short_name, long_name, stops, occasional, types, from_time, to_time, wheelchair
        $fields = [];
        $wheres = [];
        if (!$request->has('occasional')) {
            $wheres[] = "occasional <> 1";
        }
        if ($request->has('short-name')) {
            $wheres[] = 'LOWER(short_name) LIKE ?';
            $fields[] = '%' . strtolower(trim($request->input('short-name'))) . '%';
        }
        if ($request->has('long-name')) {
            $wheres[] = 'LOWER(long_name) LIKE ?';
            $fields[] = '%' . strtolower(trim($request->input('long-name'))) . '%';
        }
        if ($request->has('types')) {
            $types = explode(',', $request->input('types'));
            $qms = [];
            foreach ($types as $type) {
                switch ($type) {
                    case 'tram':
                        $fields[] = 'Villamos';
                        break;
                    case 'trolley':
                        $fields[] = 'Trolibusz';
                        break;
                    case 'bus':
                        $fields[] = 'Busz';
                    break;
                }
                $qms[] = '?';
            }
            $wheres[] = 'type IN (' . implode(',', $qms) . ')';
        }
        if ($request->has('stops')) {
            $stops = explode(',', $request->input('stops'));
            foreach ($stops as $stop) {
                $wheres[] = "id IN (SELECT route_id FROM trips, stop_times WHERE trip_id = trips.id AND stop_id IN (SELECT id FROM stops WHERE name = (SELECT name from stops WHERE id = ?)))";
                $fields[] = $stop;
            }
        }
        if ($request->has('wheelchair')) {
            $wheres[] = "id IN (SELECT route_id FROM trips WHERE wheelchair_accessible = 1)";
        }
        if ($request->has('from-time') && $request->has('to-time')) {
            $from_time = (int) $request->input('from-time');
            $to_time = (int) $request->input('to-time');
            if ($from_time > $to_time) {
                $tmp = $to_time;
                $to_time = $from_time;
                $from_time = $tmp;
            }
            $wheres[] = <<< STMT
id IN (
            SELECT route_id
    FROM trips,
        calendar,
        stop_times,
        (SELECT ABS(DAYOFWEEK(FROM_UNIXTIME(?)) - 1) AS dow) AS DOWFROM,
        (SELECT ABS(DAYOFWEEK(FROM_UNIXTIME(?)) - 1) AS dow) AS DOWTO
    WHERE calendar.service_id = trips.service_id AND trip_id = trips.id AND
        (FROM_UNIXTIME(?) <= start_date OR FROM_UNIXTIME(?) <= end_date) AND (
            DATEDIFF(FROM_UNIXTIME(?), FROM_UNIXTIME(?)) > 0 OR (
                UNIX_TIMESTAMP(arrival_time) BETWEEN (? - UNIX_TIMESTAMP(DATE(FROM_UNIXTIME(?)))) AND 
                (? - UNIX_TIMESTAMP(DATE(FROM_UNIXTIME(?)))))
             ) AND (
        (DOWFROM.dow <= DOWTO.dow AND (
            (1 BETWEEN DOWFROM.dow AND DOWTO.dow AND monday = 1) OR
                (2 BETWEEN DOWFROM.dow AND DOWTO.dow AND tuesday = 1) OR
                (3 BETWEEN DOWFROM.dow AND DOWTO.dow AND wednesday = 1) OR
                (4 BETWEEN DOWFROM.dow AND DOWTO.dow AND thursday = 1) OR
                (5 BETWEEN DOWFROM.dow AND DOWTO.dow AND friday = 1) OR
                (6 BETWEEN DOWFROM.dow AND DOWTO.dow AND saturday = 1) OR
                (0 BETWEEN DOWFROM.dow AND DOWTO.dow AND sunday = 1)
            )) OR
            ( DOWFROM.dow > DOWTO.dow AND (
                (1 BETWEEN DOWFROM.dow AND DOWTO.dow+7 AND monday = 1)OR
                (2 BETWEEN DOWFROM.dow AND DOWTO.dow+7 AND tuesday = 1) OR
                (3 BETWEEN DOWFROM.dow AND DOWTO.dow+7 AND wednesday = 1) OR
                (4 BETWEEN DOWFROM.dow AND DOWTO.dow+7 AND thursday = 1) OR
                (5 BETWEEN DOWFROM.dow AND DOWTO.dow+7 AND friday = 1) OR
                (6 BETWEEN DOWFROM.dow AND DOWTO.dow+7 AND saturday = 1) OR
                (0 BETWEEN DOWFROM.dow AND DOWTO.dow+7 AND sunday = 1)
            ))
        )
    )
STMT;
            $fields[] = $from_time;
            $fields[] = $to_time;
            $fields[] = $from_time;
            $fields[] = $to_time;
            $fields[] = $to_time;
            $fields[] = $from_time;
            $fields[] = $from_time;
            $fields[] = $from_time;
            $fields[] = $to_time;
            $fields[] = $to_time;
        }
        $query = "SELECT  id, short_name, long_name, type, occasional FROM routes" . ((!empty($wheres))?' WHERE '.implode(' AND ', $wheres):'') . ' ORDER BY type DESC, short_name';
        return response()->json(DB::select($query, $fields));
    }

    public function getRoute(Request $request, $id) {
        $route = DB::table('routes')->where('id', $id)->first();
        if (empty($route)) {
            return response("{}", 404);
        }
        $route->agency  = DB::table('agency')->where('id', $route->agency_id)->first();
        $route->stops = [];
        $stops = DB::select(<<<STMT
SELECT MIN(stop_id) AS id, MIN(name) AS name, MIN(lat) AS lat, MIN(lon) AS lon, stop_sequence, CAST(MIN(arrival_time) AS TIME) AS arrival_time, `direction_id`
FROM stop_times, trips, stops
WHERE trip_id = trips.id AND stop_id = stops.id AND route_id = :id 
GROUP BY direction_id, stop_sequence
ORDER BY direction_id, stop_sequence;
STMT
, ["id" => $id]);
        $first_arrival_to = -1;
        $first_arrival_from = -1;
        $to_stop = [];
        $from_stop = [];
        foreach ($stops as $stop) {
            if ($stop->direction_id == 0) {
                $arrival_time = date_parse($stop->arrival_time);
                $arrival_time = $arrival_time['hour'] * 60 + $arrival_time['minute'];
                if ($first_arrival_to < 0) {
                    $first_arrival_to = $arrival_time;
                }
                $arrival_time -= $first_arrival_to;
                $stop->min_offset = $arrival_time;
                unset($stop->direcion_id);
                unset($stop->arrival_time);
                $to_stop[] = $stop;
            } else {
                $arrival_time = date_parse($stop->arrival_time);
                $arrival_time = $arrival_time['hour'] * 60 + $arrival_time['minute'];
                if ($first_arrival_from < 0) {
                    $first_arrival_from = $arrival_time;
                }
                $arrival_time -= $first_arrival_from;
                $stop->min_offset = $arrival_time;
                unset($stop->direcion_id);
                unset($stop->arrival_time);
                $from_stop[] = $stop;
            }
        }
        $route->stops_to = $to_stop;
        $route->stops_from = $from_stop;
        unset($route->agency_id);
        return response()->json($route);
    }

    public function listStopsByRouteAndTimestamp(Request $request, $id) {
        if ($request->has("time")) {
            $stops = DB::select(<<<STMT
SELECT DISTINCT trip_id, CAST(arrival_time AS TIME) AS arrival_time, CAST(departure_time AS TIME) AS departure_time, stop_id, stop_sequence
FROM stop_times
WHERE trip_id IN (SELECT id
	FROM trips
	WHERE route_id = ? AND
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
	) AND
	(UNIX_TIMESTAMP(arrival_time) - ? + UNIX_TIMESTAMP(DATE(FROM_UNIXTIME(?))) ) <= 5*60 AND
	(UNIX_TIMESTAMP(arrival_time) - ? + UNIX_TIMESTAMP(DATE(FROM_UNIXTIME(?))) ) >= 0
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
            foreach ($stops as &$stop) {
                $trip_id = $stop->trip_id;
                $stop_seq = $stop->stop_sequence;
                $stop_id = $stop->stop_id;
                unset($stop->trip_id);
                unset($stop->stop_sequence);
                unset($stop->stop_id);
                $next_stop = DB::select("SELECT * FROM stops WHERE id = (SELECT stop_id FROM stop_times WHERE trip_id = ? AND stop_sequence = ?)", [$trip_id, $stop_seq + 1]);
                $stop->current_stop = DB::select("SELECT * FROM stops WHERE id = ?", [$stop_id])[0];
                $stop->next_stop = (!empty($next_stop)) ? $next_stop[0] : null;
                $stop->trip_start = DB::select("SELECT * FROM stops WHERE id = (SELECT stop_id FROM stop_times WHERE trip_id = ? AND stop_sequence = 1)", [$trip_id])[0];
                $stop->trip_end = DB::select("SELECT * FROM stops WHERE id = (SELECT stop_id FROM stop_times WHERE trip_id = ? ORDER BY stop_sequence DESC LIMIT 1)", [$trip_id])[0];
            }
            return response()->json($stops);
        }
        return response("{}",400);
    }
}