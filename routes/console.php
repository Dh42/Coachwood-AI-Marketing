<?php

// The scheduled tasks are registered in AppServiceProvider::registerSchedule
// (and each plugin's provider), not here: this file only loads for console
// requests, and the Scheduled tasks page needs the whole schedule.
