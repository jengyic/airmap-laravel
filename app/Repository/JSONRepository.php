<?php
namespace App\Repository;

use DB;
use Carbon\Carbon;
use App\Models\Group;
use App\Models\Record;
use App\Models\LatestRecord;
use App\Models\Geometry;
use App\Formatter\GroupJSONFormatter;
use App\Formatter\RecordsToChartFormatter;
use App\Service\JsonCache;

class JSONRepository
{
    /**
     * record published time filter minutes
     *
     * @var integer
     */
    public static $validMins = 30;

    /**
     * get valid published time
     *
     * @return Carbon\Carbon
     */
    protected static function validTime()
    {
        return Carbon::now()->subMinutes(static::$validMins);
    }

    /**
     * gind enabled group from group name
     *
     * @param string $group
     * @return App\Models\Group
     */
    public static function findGroup(string $group)
    {
        if ($group == 'Edimax-Airbox') {
            $group = 'edimax';
        }
        if ($group == 'Asus-Airbox') {
            $group = 'asus';
        }
        
        return Group::where('enable', true)
            ->where('name', $group)
            ->first();
    }

    /**
     * get group records
     *
     * @param string $group
     * @return Collection
     */
    public static function group($group)
    {
        if (!is_a($group, Group::class) && ! ($group = static::findGroup($group))) {
            return false;
        }

        // if cache exists, return cache
        if ($value = JsonCache::group($group->id)) {
            return $value;
        }

        // get latest record id for each uuid
        $records = LatestRecord::where('group_id', $group->id)
            ->leftJoin('lass_analyses', 'latest_records.uuid', 'lass_analyses.uuid')
            ->where('published_at', '>=', static::validTime())
            ->select(['latest_records.*', 'lass_analyses.*', 'latest_records.uuid as uuid'])
            ->get()
            ->map(function ($record) {
            return GroupJSONFormatter::format($record);
        });

        JsonCache::group($group->id, $records);
        return $records;
    }

    /**
     * get all groups records
     *
     * @return Collection
     */
    public static function groups()
    {
        $records = collect();

        Group::where('enable', true)->each(function ($group) use (&$records) {
            if ($groupRecords = static::group($group)) {
                $records = $records->merge($groupRecords);
            }
        });

        return $records;
    }

    /**
     * get latest record for specify device
     *
     * @param string $group
     * @param string $uuid
     * @return Collection
     */
    public static function latest(string $group, string $uuid)
    {
        $group = static::findGroup($group);
        if (!$group) {
            return false;
        }

        $record = LatestRecord::where('group_id', $group->id)
            ->leftJoin('lass_analyses', 'latest_records.uuid', 'lass_analyses.uuid')
            ->where('latest_records.uuid', $uuid)
            ->orderBy('published_at', 'desc')
            ->select(['latest_records.*', 'lass_analyses.*', 'latest_records.uuid as uuid'])
            ->first();

        if ($record) {
            $record = GroupJSONFormatter::format($record);
        } else {
            $record = null;
        }

        return $record;
    }

    /**
     * get history chart data for specify device
     *
     * @param string $group
     * @param string $uuid
     * @param int $start  timestamp
     * @param int $end    timestamp
     * @return array
     */
    public static function history(string $group, string $uuid, int $start, int $end)
    {
        $group = static::findGroup($group);
        if (!$group) {
            return false;
        }

        $start = Carbon::createFromTimeStampUTC($start);
        $end = Carbon::createFromTimeStampUTC($end);

        $records = Record::where('group_id', $group->id)
            ->where('records.uuid', $uuid)
            ->whereBetween('published_at', [$start, $end])
            ->orderBy('published_at', 'desc')
            ->get();

        $records = RecordsToChartFormatter::format($records);
        return $records;
    }


    public static function region(Geometry $geometry)
    {
        // if cache exists, return cache
        if ($value = JsonCache::geometry($geometry->id)) {
            return $value;
        }

        $records = LatestRecord::where('geometry_id', $geometry->id)
            ->select('pm25', 'humidity', 'temperature')
            ->get();

        $regions = [];
        foreach (['country', 'level1', 'level2', 'level3', 'level4'] as $region) {
            if ($geometry->{$region}) {
                $regions[] = $geometry->{$region};
            }
        }

        $record = collect([
            'regions' => $regions,
            'site_count' => $records->count(),
            'pm25' => $records->pluck('pm25')->avg(),
            'humidity' => $records->pluck('humidity')->avg(),
            'temperature' => $records->pluck('temperature')->avg(),
        ]);

        JsonCache::geometry($geometry->id, $record);
        return $record;
    }

    public static function bounds(array $northEast, array $sourthWest)
    {
        $westlng = $sourthWest['lng'];
        $eastlng = $northEast['lng'];
        $northlat = $northEast['lat'];
        $southlat = $sourthWest['lat'];

        $records = LatestRecord::whereBetween('lat', [$southlat, $northlat])
            ->whereBetween('lng', [$westlng, $eastlng])
            ->leftJoin('lass_analyses', 'latest_records.uuid', 'lass_analyses.uuid')
            ->select(['latest_records.*', 'lass_analyses.*', 'latest_records.uuid as uuid'])
            ->get()
            ->map(function ($record) {
            return GroupJSONFormatter::format($record);
        });

        return $records;
    }
}
