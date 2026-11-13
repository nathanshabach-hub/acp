<?php

namespace App\Controller\Admin;

use App\Controller\AppController;

#[\AllowDynamicProperties]
class SchedulingtweaksController extends AppController {

    public array $paginate = ['limit' => 100];

    public $Conventionseasons = null;
    public $Conventions = null;
    public $Conventionseasonevents = null;
    public $Conventionrooms = null;
    public $Conventionseasonroomevents = null;
    public $Schedulingeventtweaks = null;
    public $Events = null;
    public $Schedulings = null;
    public $Schedulingroomlimits = null;
    public $Schedulingtimings = null;

    public function initialize(): void {
        parent::initialize();
        $this->loadComponent('Flash');

        $this->Conventionseasons           = $this->loadModel('Conventionseasons');
        $this->Conventions                 = $this->loadModel('Conventions');
        $this->Conventionseasonevents      = $this->loadModel('Conventionseasonevents');
        $this->Conventionrooms             = $this->loadModel('Conventionrooms');
        $this->Conventionseasonroomevents  = $this->loadModel('Conventionseasonroomevents');
        $this->Schedulingeventtweaks       = $this->loadModel('Schedulingeventtweaks');
        $this->Events                      = $this->loadModel('Events');
        $this->Schedulings                 = $this->loadModel('Schedulings');
        $this->Schedulingroomlimits        = $this->loadModel('Schedulingroomlimits');
        $this->Schedulingtimings           = $this->loadModel('Schedulingtimings');
    }

    private function normalizeTimeInput($value, &$isInvalid = false) {
        $isInvalid = false;

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $filtered = [];
            foreach ($value as $key => $part) {
                if ($part === null) {
                    continue;
                }
                $part = trim((string)$part);
                if ($part !== '') {
                    $filtered[$key] = $part;
                }
            }

            if (empty($filtered)) {
                return null;
            }

            if (!empty($filtered['hour']) && !empty($filtered['minute'])) {
                $hour = (int)$filtered['hour'];
                $minute = (int)$filtered['minute'];
                $second = !empty($filtered['second']) ? (int)$filtered['second'] : 0;
                $meridian = strtolower((string)($filtered['meridian'] ?? ''));

                if ($meridian === 'pm' && $hour < 12) {
                    $hour += 12;
                } elseif ($meridian === 'am' && $hour === 12) {
                    $hour = 0;
                }

                if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
                    $isInvalid = true;
                    return null;
                }

                return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
            }

            if (!empty($filtered['time'])) {
                $value = $filtered['time'];
            } else {
                $value = implode(' ', $filtered);
            }
        }

        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
            return strlen($value) === 5 ? $value . ':00' : $value;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            $isInvalid = true;
            return null;
        }

        return date('H:i:s', $timestamp);
    }

    private function loadConcurrentStudentsMap(int $conventionSeasonId): array {
        $connection = $this->Schedulingeventtweaks->getConnection();
        $rows = $connection->execute(
            'SELECT event_id, concurrent_students_at_once FROM schedulingeventtweaks WHERE conventionseasons_id = :season_id',
            ['season_id' => $conventionSeasonId]
        )->fetchAll('assoc');

        $map = [];
        foreach ($rows as $row) {
            $eventId = (int)($row['event_id'] ?? 0);
            if ($eventId < 1) {
                continue;
            }
            $raw = $row['concurrent_students_at_once'] ?? null;
            $map[$eventId] = $raw === null ? null : (int)$raw;
        }

        return $map;
    }

    private function persistConcurrentStudentsAtOnce(int $conventionSeasonId, int $eventId, ?int $concurrentStudentsAtOnce): void {
        $connection = $this->Schedulingeventtweaks->getConnection();
        $connection->execute(
            'UPDATE schedulingeventtweaks SET concurrent_students_at_once = :value WHERE conventionseasons_id = :season_id AND event_id = :event_id',
            [
                'value' => $concurrentStudentsAtOnce,
                'season_id' => $conventionSeasonId,
                'event_id' => $eventId,
            ]
        );
    }

    /* ------------------------------------------------------------------ */
    /*  INDEX – list all events for this convention season with tweak controls */
    /* ------------------------------------------------------------------ */
    public function index($convention_season_slug = null) {
        $this->set('title', ADMIN_TITLE . 'Scheduling Tweaks');
        $this->viewBuilder()->setLayout('admin');
        $this->set('manageConventions', '1');
        $this->set('conventionList', '1');
        $this->set('convention_season_slug', $convention_season_slug);

        $conventionSD = $this->Conventionseasons->find()
            ->where(['Conventionseasons.slug' => $convention_season_slug])
            ->contain(['Conventions'])
            ->first();
        $this->set('conventionSD', $conventionSD);
        $this->set('convention_slug', $conventionSD->Conventions['slug']);

        /* All events registered for this convention season */
        $cseList = $this->Conventionseasonevents->find()
            ->where([
                'Conventionseasonevents.conventionseasons_id' => $conventionSD->id,
                'Conventionseasonevents.convention_id'        => $conventionSD->convention_id,
            ])
            ->all();

        /* Build an array of events that need_schedule */
        $eventsForTweaks = [];
        foreach ($cseList as $cse) {
            $eventD = $this->Events->find()->where(['Events.id' => $cse->event_id])->first();
            if ($eventD && $eventD->needs_schedule == '1') {
                $eventsForTweaks[] = $eventD;
            }
        }
        $this->set('eventsForTweaks', $eventsForTweaks);

        /* Existing tweaks indexed by event_id */
        $tweakRows = $this->Schedulingeventtweaks->find()
            ->where(['Schedulingeventtweaks.conventionseasons_id' => $conventionSD->id])
            ->all();
        $concurrentStudentsMap = $this->loadConcurrentStudentsMap((int)$conventionSD->id);
        $tweaksMap = [];
        foreach ($tweakRows as $tw) {
            $eventId = (int)($tw->event_id ?? 0);
            if ($eventId > 0 && array_key_exists($eventId, $concurrentStudentsMap)) {
                $tw->concurrent_students_at_once = $concurrentStudentsMap[$eventId];
            }
            $tweaksMap[$tw->event_id] = $tw;
        }
        $this->set('tweaksMap', $tweaksMap);

        /* Rooms for this convention (for the pinned-room dropdown) */
        $rooms = $this->Conventionrooms->find()
            ->where(['Conventionrooms.convention_id' => $conventionSD->convention_id])
            ->order(['Conventionrooms.room_name' => 'ASC'])
            ->all();
        $this->set('rooms', $rooms);

        /* Days dropdown from the scheduling wizard */
        $schedulingD = $this->Schedulings->find()
            ->where(['Schedulings.conventionseasons_id' => $conventionSD->id])
            ->first();
        $this->set('schedulingD', $schedulingD);

        global $weekDays;
        $this->set('weekDays', $weekDays);
    }

    /* ------------------------------------------------------------------ */
    /*  SAVE – AJAX / POST: save or update a single event tweak            */
    /* ------------------------------------------------------------------ */
    public function save($convention_season_slug = null) {
        $this->request->allowMethod(['post']);

        $conventionSD = $this->Conventionseasons->find()
            ->where(['Conventionseasons.slug' => $convention_season_slug])
            ->first();

        $postData  = (array) $this->request->getData();
        $event_id  = (int) ($postData['event_id'] ?? 0);

        if (!$conventionSD || $event_id < 1) {
            $this->Flash->error('Invalid request.');
            return $this->redirect(['action' => 'index', $convention_season_slug]);
        }

        /* Find or create the tweak record */
        $existing = $this->Schedulingeventtweaks->find()
            ->where([
                'Schedulingeventtweaks.conventionseasons_id' => $conventionSD->id,
                'Schedulingeventtweaks.event_id'             => $event_id,
            ])
            ->first();

        if ($existing) {
            $tweak = $existing;
        } else {
            $tweak = $this->Schedulingeventtweaks->newEntity([]);
            $tweak->conventionseasons_id = $conventionSD->id;
            $tweak->event_id             = $event_id;
            $tweak->created              = date('Y-m-d H:i:s');
        }

        /* A: pinned day */
        $tweak->pinned_day = !empty($postData['pinned_day']) ? trim($postData['pinned_day']) : null;

        /* C: pinned start time */
        $rawTime = $postData['pinned_start_time'] ?? null;
        $invalidTime = false;
        $normalizedPinnedStart = $this->normalizeTimeInput($rawTime, $invalidTime);
        if ($invalidTime) {
            $this->Flash->error('Invalid pinned start time format.');
            return $this->redirect(['action' => 'index', $convention_season_slug]);
        }
        $tweak->pinned_start_time = $normalizedPinnedStart;

        /* D: event availability window */
        $rawAvailableFrom = $postData['available_from_time'] ?? null;
        $rawAvailableTo = $postData['available_to_time'] ?? null;

        $availableFrom = null;
        $availableTo = null;
        $invalidAvailableFrom = false;
        $availableFrom = $this->normalizeTimeInput($rawAvailableFrom, $invalidAvailableFrom);
        if ($invalidAvailableFrom) {
            $this->Flash->error('Invalid Available From time format.');
            return $this->redirect(['action' => 'index', $convention_season_slug]);
        }

        $invalidAvailableTo = false;
        $availableTo = $this->normalizeTimeInput($rawAvailableTo, $invalidAvailableTo);
        if ($invalidAvailableTo) {
            $this->Flash->error('Invalid Available To time format.');
            return $this->redirect(['action' => 'index', $convention_season_slug]);
        }

        if ($availableFrom !== null && $availableTo !== null && strtotime($availableFrom) >= strtotime($availableTo)) {
            $this->Flash->error('Available From must be earlier than Available To.');
            return $this->redirect(['action' => 'index', $convention_season_slug]);
        }

        $tweak->available_from_time = $availableFrom;
        $tweak->available_to_time = $availableTo;

        /* B: pinned room */
        $tweak->pinned_room_id = !empty($postData['pinned_room_id']) ? (int) $postData['pinned_room_id'] : null;
        $concurrentAtOnceRaw = isset($postData['concurrent_students_at_once']) ? trim((string)$postData['concurrent_students_at_once']) : '';
        if ($concurrentAtOnceRaw === '') {
            $tweak->concurrent_students_at_once = null;
        } else {
            $concurrentAtOnce = (int)$concurrentAtOnceRaw;
            if ($concurrentAtOnce < 1) {
                $this->Flash->error('Students-at-once must be at least 1 when provided.');
                return $this->redirect(['action' => 'index', $convention_season_slug]);
            }
            $tweak->concurrent_students_at_once = $concurrentAtOnce;
        }

        $tweak->modified = date('Y-m-d H:i:s');

        if ($this->Schedulingeventtweaks->save($tweak)) {
            $this->persistConcurrentStudentsAtOnce((int)$conventionSD->id, (int)$event_id, $tweak->concurrent_students_at_once === null ? null : (int)$tweak->concurrent_students_at_once);
            $this->Flash->success('Tweak saved.');
        } else {
            $this->Flash->error('Could not save tweak. Check server logs.');
        }

        return $this->redirect(['action' => 'index', $convention_season_slug]);
    }

    /* ------------------------------------------------------------------ */
    /*  BULKSAVE – apply selected tweak fields to multiple events          */
    /* ------------------------------------------------------------------ */
    public function bulksave($convention_season_slug = null) {
        $this->request->allowMethod(['post']);

        $conventionSD = $this->Conventionseasons->find()
            ->where(['Conventionseasons.slug' => $convention_season_slug])
            ->first();

        if (!$conventionSD) {
            $this->Flash->error('Invalid convention season.');
            return $this->redirect(['action' => 'index', $convention_season_slug]);
        }

        $postData = (array) $this->request->getData();
        $eventIdsRaw = $postData['event_ids'] ?? [];
        if (!is_array($eventIdsRaw)) {
            $eventIdsRaw = [$eventIdsRaw];
        }

        $eventIds = [];
        foreach ($eventIdsRaw as $eventIdRaw) {
            $eventId = (int) $eventIdRaw;
            if ($eventId > 0) {
                $eventIds[$eventId] = $eventId;
            }
        }
        $eventIds = array_values($eventIds);

        if (empty($eventIds)) {
            $this->Flash->error('Select at least one event for bulk edit.');
            return $this->redirect(['action' => 'index', $convention_season_slug]);
        }

        $applyPinnedDay = !empty($postData['apply_pinned_day']);
        $applyPinnedRoom = !empty($postData['apply_pinned_room_id']);
        $applyPinnedStart = !empty($postData['apply_pinned_start_time']);
        $applyWindow = !empty($postData['apply_available_window']);
        $applyConcurrentAtOnce = !empty($postData['apply_concurrent_students_at_once']);

        if (!$applyPinnedDay && !$applyPinnedRoom && !$applyPinnedStart && !$applyWindow && !$applyConcurrentAtOnce) {
            $this->Flash->error('Choose at least one field to apply in bulk edit.');
            return $this->redirect(['action' => 'index', $convention_season_slug]);
        }

        $pinnedDay = null;
        if ($applyPinnedDay) {
            $pinnedDay = !empty($postData['pinned_day']) ? trim((string) $postData['pinned_day']) : null;
        }

        $pinnedRoomId = null;
        if ($applyPinnedRoom) {
            $pinnedRoomId = !empty($postData['pinned_room_id']) ? (int) $postData['pinned_room_id'] : null;
        }

        $pinnedStart = null;
        if ($applyPinnedStart) {
            $rawPinnedStart = $postData['pinned_start_time'] ?? null;
            $invalidPinnedStart = false;
            $pinnedStart = $this->normalizeTimeInput($rawPinnedStart, $invalidPinnedStart);
            if ($invalidPinnedStart) {
                $this->Flash->error('Invalid pinned start time format.');
                return $this->redirect(['action' => 'index', $convention_season_slug]);
            }
        }

        $availableFrom = null;
        $availableTo = null;
        $concurrentAtOnce = null;
        if ($applyWindow) {
            $rawAvailableFrom = $postData['available_from_time'] ?? null;
            $rawAvailableTo = $postData['available_to_time'] ?? null;

            $invalidAvailableFrom = false;
            $availableFrom = $this->normalizeTimeInput($rawAvailableFrom, $invalidAvailableFrom);
            if ($invalidAvailableFrom) {
                $this->Flash->error('Invalid Available From time format.');
                return $this->redirect(['action' => 'index', $convention_season_slug]);
            }

            $invalidAvailableTo = false;
            $availableTo = $this->normalizeTimeInput($rawAvailableTo, $invalidAvailableTo);
            if ($invalidAvailableTo) {
                $this->Flash->error('Invalid Available To time format.');
                return $this->redirect(['action' => 'index', $convention_season_slug]);
            }

            if ($availableFrom !== null && $availableTo !== null && strtotime($availableFrom) >= strtotime($availableTo)) {
                $this->Flash->error('Available From must be earlier than Available To.');
                return $this->redirect(['action' => 'index', $convention_season_slug]);
            }
        }
        if ($applyConcurrentAtOnce) {
            $concurrentRaw = isset($postData['concurrent_students_at_once']) ? trim((string)$postData['concurrent_students_at_once']) : '';
            if ($concurrentRaw === '') {
                $concurrentAtOnce = null;
            } else {
                $concurrentAtOnce = (int)$concurrentRaw;
                if ($concurrentAtOnce < 1) {
                    $this->Flash->error('Bulk students-at-once must be at least 1 when provided.');
                    return $this->redirect(['action' => 'index', $convention_season_slug]);
                }
            }
        }

        $savedCount = 0;
        foreach ($eventIds as $eventId) {
            $existing = $this->Schedulingeventtweaks->find()
                ->where([
                    'Schedulingeventtweaks.conventionseasons_id' => $conventionSD->id,
                    'Schedulingeventtweaks.event_id' => $eventId,
                ])
                ->first();

            if ($existing) {
                $tweak = $existing;
            } else {
                $tweak = $this->Schedulingeventtweaks->newEntity([]);
                $tweak->conventionseasons_id = $conventionSD->id;
                $tweak->event_id = $eventId;
                $tweak->created = date('Y-m-d H:i:s');
            }

            if ($applyPinnedDay) {
                $tweak->pinned_day = $pinnedDay;
            }
            if ($applyPinnedRoom) {
                $tweak->pinned_room_id = $pinnedRoomId;
            }
            if ($applyPinnedStart) {
                $tweak->pinned_start_time = $pinnedStart;
            }
            if ($applyWindow) {
                $tweak->available_from_time = $availableFrom;
                $tweak->available_to_time = $availableTo;
            }
            if ($applyConcurrentAtOnce) {
                $tweak->concurrent_students_at_once = $concurrentAtOnce;
            }

            $tweak->modified = date('Y-m-d H:i:s');
            if ($this->Schedulingeventtweaks->save($tweak)) {
                if ($applyConcurrentAtOnce) {
                    $this->persistConcurrentStudentsAtOnce((int)$conventionSD->id, (int)$eventId, $concurrentAtOnce);
                }
                $savedCount++;
            }
        }

        if ($savedCount > 0) {
            $this->Flash->success('Bulk tweaks applied to ' . $savedCount . ' event(s).');
        } else {
            $this->Flash->error('No tweaks were saved in bulk edit.');
        }

        return $this->redirect(['action' => 'index', $convention_season_slug]);
    }

    /* ------------------------------------------------------------------ */
    /*  CLEAR – remove all tweaks for one event                            */
    /* ------------------------------------------------------------------ */
    public function clear($convention_season_slug = null, $event_id = null) {
        $this->request->allowMethod(['post', 'get']);

        $conventionSD = $this->Conventionseasons->find()
            ->where(['Conventionseasons.slug' => $convention_season_slug])
            ->first();

        if ($conventionSD && $event_id) {
            $this->Schedulingeventtweaks->deleteAll([
                'Schedulingeventtweaks.conventionseasons_id' => $conventionSD->id,
                'Schedulingeventtweaks.event_id'             => (int) $event_id,
            ]);
            $this->Flash->success('Tweak cleared.');
        }

        return $this->redirect(['action' => 'index', $convention_season_slug]);
    }

    /* ------------------------------------------------------------------ */
    /*  ROOMAVAILABILITY – set available_from / available_to per room      */
    /* ------------------------------------------------------------------ */
    public function roomavailability($convention_season_slug = null) {
        $this->set('title', ADMIN_TITLE . 'Room Availability Windows');
        $this->viewBuilder()->setLayout('admin');
        $this->set('manageConventions', '1');
        $this->set('conventionList', '1');
        $this->set('convention_season_slug', $convention_season_slug);

        $conventionSD = $this->Conventionseasons->find()
            ->where(['Conventionseasons.slug' => $convention_season_slug])
            ->contain(['Conventions'])
            ->first();
        $this->set('conventionSD', $conventionSD);
        $this->set('convention_slug', $conventionSD->Conventions['slug']);

        $rooms = $this->Conventionrooms->find()
            ->where(['Conventionrooms.convention_id' => $conventionSD->convention_id])
            ->order(['Conventionrooms.room_name' => 'ASC'])
            ->all();
        $this->set('rooms', $rooms);

        if ($this->request->is(['post'])) {
            $postData = (array) $this->request->getData();
            foreach ($rooms as $room) {
                $fromRaw = $postData['available_from'][$room->id] ?? null;
                $toRaw   = $postData['available_to'][$room->id] ?? null;

                $invalidFrom = false;
                $availFrom = $this->normalizeTimeInput($fromRaw, $invalidFrom);
                if ($invalidFrom) {
                    $this->Flash->error('Invalid Available From time format for room ' . $room->room_name . '.');
                    return $this->redirect(['action' => 'roomavailability', $convention_season_slug]);
                }

                $invalidTo = false;
                $availTo = $this->normalizeTimeInput($toRaw, $invalidTo);
                if ($invalidTo) {
                    $this->Flash->error('Invalid Available To time format for room ' . $room->room_name . '.');
                    return $this->redirect(['action' => 'roomavailability', $convention_season_slug]);
                }

                $this->Conventionrooms->updateAll(
                    ['available_from' => $availFrom, 'available_to' => $availTo, 'modified' => date('Y-m-d H:i:s')],
                    ['id' => $room->id]
                );
            }
            $this->Flash->success('Room availability windows saved.');
            return $this->redirect(['action' => 'roomavailability', $convention_season_slug]);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  ROOM LIMITS – show booked hours per room per day + set max hours   */
    /* ------------------------------------------------------------------ */
    public function roomlimits($convention_season_slug = null) {
        $this->set('title', ADMIN_TITLE . 'Room Time Allocation');
        $this->viewBuilder()->setLayout('admin');
        $this->set('manageConventions', '1');
        $this->set('conventionList', '1');
        $this->set('convention_season_slug', $convention_season_slug);

        $conventionSD = $this->Conventionseasons->find()
            ->where(['Conventionseasons.slug' => $convention_season_slug])
            ->contain(['Conventions'])
            ->first();
        $this->set('conventionSD', $conventionSD);
        $this->set('convention_slug', $conventionSD->Conventions['slug']);

        $seasonId = $conventionSD->id;

        /* Convention days ordered */
        $weekArr     = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        $allowedDays = [];
        $schedulingD = $this->Schedulings->find()
            ->where(['conventionseasons_id' => $seasonId])
            ->first();
        if ($schedulingD && $schedulingD->first_day && $schedulingD->number_of_days > 0) {
            $keyStart = array_search($schedulingD->first_day, $weekArr);
            if ($keyStart !== false) {
                for ($d = 0; $d < $schedulingD->number_of_days; $d++) {
                    $allowedDays[] = $weekArr[($keyStart + $d) % 7];
                }
            }
        }
        if (empty($allowedDays)) {
            /* Fallback: pull distinct days from timings */
            $distinctDays = $this->Schedulingtimings->find()
                ->select(['day'])
                ->where(['conventionseasons_id' => $seasonId])
                ->distinct()
                ->order(['day' => 'ASC'])
                ->all();
            foreach ($distinctDays as $row) {
                if (!empty($row->day)) $allowedDays[] = $row->day;
            }
        }
        $this->set('allowedDays', $allowedDays);

        /* Only rooms used in this convention season's room-event assignments */
        $assignedSeasonRoomIds = $this->Conventionseasonroomevents->find()
            ->select(['room_id'])
            ->where([
                'Conventionseasonroomevents.conventionseasons_id' => $conventionSD->id,
                'Conventionseasonroomevents.convention_id' => $conventionSD->convention_id,
                'Conventionseasonroomevents.season_id' => $conventionSD->season_id,
                'Conventionseasonroomevents.season_year' => $conventionSD->season_year,
            ])
            ->all()
            ->extract('room_id')
            ->map(static function ($roomId) {
                return (int)$roomId;
            })
            ->toList();
        $assignedSeasonRoomIds = array_values(array_unique(array_filter($assignedSeasonRoomIds)));

        $roomConditions = ['Conventionrooms.convention_id' => $conventionSD->convention_id];
        if (!empty($assignedSeasonRoomIds)) {
            $roomConditions['Conventionrooms.id IN'] = $assignedSeasonRoomIds;
        } else {
            $roomConditions['Conventionrooms.id'] = -1;
        }

        $rooms = $this->Conventionrooms->find()
            ->where($roomConditions)
            ->order(['Conventionrooms.room_name' => 'ASC'])
            ->all();
        $this->set('rooms', $rooms);

        /* Existing max-hours limits */
        $limitsRaw = $this->Schedulingroomlimits->find()
            ->where(['conventionseasons_id' => $seasonId])
            ->all();
        $limits = []; /* [room_id][day] => max_hours */
        foreach ($limitsRaw as $lim) {
            $limits[$lim->room_id][$lim->day] = $lim->max_hours;
        }
        $this->set('limits', $limits);

        /* Booked minutes per room per day from schedulingtimings (raw query) */
        $conn      = $this->Schedulingtimings->getConnection();
        $bookedStmt = $conn->execute(
            'SELECT room_id, day,
                    SUM(TIME_TO_SEC(TIMEDIFF(finish_time, start_time)) / 60) AS total_minutes,
                    COUNT(*) AS total_rows
             FROM schedulingtimings
             WHERE conventionseasons_id = :sid AND day IS NOT NULL AND day != \'\'
             GROUP BY room_id, day',
            ['sid' => $seasonId]
        );
        $booked = []; /* [room_id][day] => minutes */
        $scheduledCounts = []; /* [room_id][day] => row count */
        foreach ($bookedStmt->fetchAll('assoc') as $row) {
            $booked[$row['room_id']][$row['day']] = (float) $row['total_minutes'];
            $scheduledCounts[$row['room_id']][$row['day']] = (int) $row['total_rows'];
        }
        $this->set('booked', $booked);
        $this->set('scheduledCounts', $scheduledCounts);

        /* Handle save */
        if ($this->request->is(['post'])) {
            $postData    = (array) $this->request->getData();
            $maxHoursAll = $postData['max_hours'] ?? [];
            $now         = date('Y-m-d H:i:s');
            foreach ($rooms as $room) {
                foreach ($allowedDays as $day) {
                    $val = isset($maxHoursAll[$room->id][$day]) && $maxHoursAll[$room->id][$day] !== ''
                        ? (float) $maxHoursAll[$room->id][$day] : null;

                    /* Upsert */
                    $existing = $this->Schedulingroomlimits->find()
                        ->where(['conventionseasons_id' => $seasonId, 'room_id' => $room->id, 'day' => $day])
                        ->first();
                    if ($existing) {
                        $this->Schedulingroomlimits->updateAll(
                            ['max_hours' => $val, 'modified' => $now],
                            ['id' => $existing->id]
                        );
                    } else {
                        $entity = $this->Schedulingroomlimits->newEntity([
                            'conventionseasons_id' => $seasonId,
                            'room_id'              => $room->id,
                            'day'                  => $day,
                            'max_hours'            => $val,
                            'created'              => $now,
                            'modified'             => $now,
                        ]);
                        $this->Schedulingroomlimits->save($entity);
                    }
                }
            }
            $this->Flash->success('Room time limits saved.');
            return $this->redirect(['action' => 'roomlimits', $convention_season_slug]);
        }
    }
}
