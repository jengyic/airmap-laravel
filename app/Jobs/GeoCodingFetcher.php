<?php

namespace App\Jobs;

use App\Models\Record;
use App\Service\GeoCoding;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class GeoCodingFetcher implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $geoService = new GeoCoding();
        $cnt = 0;
        $max = 500;
        $chunk = 100;

        Record::where('lat', '<>', 0)
            ->where('lng', '<>', 0)
            ->whereNotIn('id', function ($query) {
                $query->select('record_id')->from('geometry_record');
            })->chunk($chunk, function ($records) use ($geoService, &$cnt, $max, $chunk) {
                $cnt += $chunk;

                $results = $records->map(function ($record) use ($geoService) {
                    $bound = $geoService->findLatLng($record->lat, $record->lng);
                    
                    if ($bound) {
                        $record->geometries()->attach($bound->id);
                        return $record->id;
                    }
                });

                if ($cnt >= $max) { return false; }
            });
    }
}
