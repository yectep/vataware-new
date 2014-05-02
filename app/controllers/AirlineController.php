<?php

class AirlineController extends BaseController {
	
	protected $layout = 'layouts.master';

	function index() {
		$airlines = Airline::orderBy('name')->paginate(50);

		$this->autoRender(compact('airlines'), 'Airlines');
	}

	function show(Airline $airline) {
		$activeFlights = $airline->flights()
			->whereIn('state',[0, 1, 3, 4])
			->join('pilots','flights.vatsim_id','=','pilots.vatsim_id')
			->with(['departure' => function($departure) { $departure->remember(15); },'arrival' => function($arrival) { $arrival->remember(15); }])
			->select('flights.*','pilots.name')
			->orderBy('departure_time','desc')
			->remember(15)->get();
		$historicFlights = $airline->flights()
			->whereState(2)
			->join('pilots','flights.vatsim_id','=','pilots.vatsim_id')
			->with(['departure' => function($departure) { $departure->remember(15); },'arrival' => function($arrival) { $arrival->remember(15); }])
			->select('flights.*','pilots.name')
			->orderBy('departure_time','desc')
			->take(25)->remember(15)->get();
		
		$totalDuration = $airline->flights()->whereState(2)->remember(120)->sum('duration');

		if($totalDuration > 0) {
			$pilots = $airline->flights()
				->whereState(2)
				->leftJoin('pilots','flights.vatsim_id','=','pilots.vatsim_id')
				->select('pilots.*', DB::raw('SUM(flights.duration) AS duration'))
				->orderBy('duration','desc')->groupBy('flights.vatsim_id')
				->take(5)->remember(120)->get()
				->transform(function($pilot) use ($totalDuration) {
					return array('name' => $pilot->name, 'duration' => $pilot->duration, 'percent' => number_format($pilot->duration/$totalDuration * 100, 1));
				});
			$pilots->add(array('name' => 'Others', 'duration' => ($totalDuration - $pilots->sum('duration')), 'percent' => number_format(($totalDuration - $pilots->sum('duration'))/$totalDuration * 100, 1)));
			$pilots = $pilots->toArray();
			foreach($pilots as &$pilot) {
				$pilot = array($pilot['name'], $pilot['duration']);
			}

			$aircraft = $airline->flights()
				->whereState(2)->whereNotNull('aircraft_id')->where('aircraft_id','!=','')
				->with(['aircraft' => function($aircraft) { $aircraft->remember(120); }])
				->select('aircraft_id', DB::raw('SUM(duration) AS duration'))
				->orderBy('duration','desc')->groupBy('aircraft_id')
				->take(5)->remember(120)->get()
				->transform(function($aircraft) use ($totalDuration) {
					return array('name' => $aircraft->aircraft->implode('name','<br />'), 'duration' => $aircraft->duration, 'percent' => number_format($aircraft->duration/$totalDuration * 100, 1));
				});

			$aircraft->add(array('name' => 'Other', 'duration' => ($totalDuration - $aircraft->sum('duration')), 'percent' => number_format(($totalDuration - $aircraft->sum('duration'))/$totalDuration * 100, 1)));
			$aircraft = $aircraft->toArray();
			foreach($aircraft as &$airplane) {
				$airplane = array($airplane['name'], $airplane['duration']);
			}
		} else {
			$pilots = array();
			$aircraft = array();
		}

		$pilots = piechartData($pilots)['javascript'];
		$aircraft = piechartData($aircraft)['javascript'];

		$airlines = Cache::get('legacy.airlines', array());
		if(!in_array($airline->icao, $airlines)) {
			for($i = 2007; $i <= 2014; $i++) {
				Queue::push('LegacyUpdateAirline', array('airline' => $airline->icao, 'year' => $i), 'legacy');
			}
			$airlines[] = $airline->icao;
			Cache::forever('legacy.airlines', $airlines);
			Messages::warning('Data for this airline may be missing. It is being processed by year.')->one();
		}

		$this->javascript('assets/javascript/jquery.flot.min.js');
		$this->javascript('assets/javascript/jquery.flot.pie.min.js');
		$this->autoRender(compact('airline','historicFlights','pilots','aircraft','activeFlights'), $airline->icao . ' - ' . $airline->name);
	}

}