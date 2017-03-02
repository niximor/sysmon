<?php

interface CronInterface {
    public function __construct();
    public function cron(mysqli $db);
}
