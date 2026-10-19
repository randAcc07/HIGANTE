<?php

namespace Database\Seeders\Demo;

use App\Enums\RoomType;
use App\Models\College;
use App\Models\Program;
use App\Models\Room;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * Colleges, programs, subjects and rooms (named where people use a name),
 * straight from CampusData.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (CampusData::COLLEGES as $code => $college) {
            $model = College::create(['code' => $code, 'name' => $college['name']]);

            foreach (CampusData::GENERAL_EDUCATION as $subject) {
                $this->subject($model, $subject);
            }

            foreach (CampusData::PROGRAMS[$code] as $programCode => $program) {
                Program::create(['college_id' => $model->id, 'code' => $programCode, 'name' => $program['name']]);

                foreach ($program['years'] as $subjects) {
                    foreach ($subjects as $subject) {
                        $this->subject($model, $subject);
                    }
                }
            }

            foreach ($college['lecture_rooms'] as $number) {
                Room::create([
                    'code' => $college['room_prefix'].'-'.$number,
                    'building' => $college['building'],
                    'floor' => intdiv($number, 100),
                    'capacity' => $number % 2 === 0 ? 40 : null,
                    'room_type' => RoomType::Lecture,
                ]);
            }

            foreach ($college['labs'] as $rooms) {
                foreach ($rooms as $roomCode => $capacity) {
                    Room::create([
                        'code' => $roomCode,
                        'building' => $college['building'],
                        'floor' => 1,
                        'capacity' => $capacity,
                        'room_type' => RoomType::Laboratory,
                    ]);
                }
            }
        }

        foreach (CampusData::SHARED_ROOMS as $room) {
            Room::create(['room_type' => RoomType::Lecture, ...$room]);
        }

        foreach (CampusData::ROOM_NAMES as $code => $name) {
            Room::where('code', $code)->sole()->update(['name' => $name]);
        }
    }

    /**
     * @param  array{0: string, 1: string, 2: int, 3: int, 4?: array<string, mixed>}  $subject
     */
    private function subject(College $college, array $subject): void
    {
        Subject::firstOrCreate(
            ['college_id' => $college->id, 'code' => $subject[0]],
            ['title' => $subject[1], 'lecture_units' => $subject[2], 'laboratory_units' => $subject[3]],
        );
    }
}
