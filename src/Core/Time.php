<?php

namespace SPGame\Core;


class Time
{

    static float $StartTime = 0.0;

    static function Start(): void
    {
        self::$StartTime = microtime(true);
    }

    static function WorkTime(bool $Time = false, bool $form = true): string|float
    {
        if ($Time)
            return self::SecontToTime(microtime(true) - self::$StartTime, $form);

        return microtime(true) - self::$StartTime;
    }


    /*
    function TimeInterval($time, $intval)
    {
        return $this->StartTimeMicro - $time > $intval;
    }

    function DiffTimeCurrent($time)
    {
        return $this->StartTimeMicro - $time;
    }
*/
    static function SecontToTime(float $sec, bool $form = true): string
    {
        $res = array();

        $res['d'] = (int)floor($sec / 86400);
        $sec = fmod($sec, 86400);
        $res['h'] = (int)floor($sec / 3600);
        $sec = fmod($sec, 3600);
        $res['m'] = (int)floor($sec / 60);
        $res['s'] = (int)fmod($sec, 60);

        return vsprintf("%d %'.02d:%'.02d:%'.02d", $res);
    }
}

//$Time = new Time();
