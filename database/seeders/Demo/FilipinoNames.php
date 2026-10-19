<?php

namespace Database\Seeders\Demo;

use Illuminate\Support\Str;

/**
 * Filipino-sounding names (Faker has no Philippine person provider) and
 * unique login emails built from them. Uses the global Mersenne Twister, so
 * a fixed mt_srand() seed gives the same people every run.
 */
final class FilipinoNames
{
    private const MALE = [
        'Juan', 'Jose', 'Mark', 'John Paul', 'Christian', 'Angelo', 'Carlo', 'Miguel', 'Paolo', 'Rafael',
        'Joshua', 'Kenneth', 'Jerome', 'Ramon', 'Gabriel', 'Adrian', 'Vincent', 'Bryan', 'Renz', 'Jericho',
        'Aldrin', 'Ricardo', 'Emmanuel', 'Francis', 'Patrick', 'Kyle', 'Nathaniel', 'Earl', 'Lance', 'Joaquin',
        'Marvin', 'Ronald', 'Dominic', 'Jayson', 'Elijah', 'Rommel', 'Noel', 'Arnel', 'Gerald', 'Ivan',
    ];

    private const FEMALE = [
        'Maria', 'Angelica', 'Kristine', 'Camille', 'Jasmine', 'Patricia', 'Nicole', 'Andrea', 'Princess', 'Mary Joy',
        'Christine', 'Danica', 'Bea', 'Janelle', 'Hazel', 'Rose Ann', 'Shiela', 'Katrina', 'Joanna', 'Erika',
        'Trisha', 'Aira', 'Mikaela', 'Althea', 'Lovely', 'Jessa', 'Rhea', 'Ella', 'Kimberly', 'Precious',
        'Liza', 'Marites', 'Charmaine', 'Lea', 'Sofia', 'Gwyneth', 'Kathleen', 'Pauline', 'Bianca', 'Denise',
    ];

    private const SURNAMES = [
        'Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Mendoza', 'Bautista', 'Villanueva', 'Ramos', 'Castillo', 'Aquino',
        'Navarro', 'Francisco', 'Torres', 'Flores', 'Gonzales', 'Cruz', 'Rivera', 'Mercado', 'Pascual', 'Domingo',
        'Salazar', 'Fernandez', 'Aguilar', 'Valdez', 'Soriano', 'Manalo', 'Gutierrez', 'Ocampo', 'Lopez', 'Dizon',
        'Marquez', 'Tolentino', 'De Leon', 'Rosales', 'Sison', 'Del Rosario', 'Panganiban', 'Bernardo', 'Cabrera', 'Magbanua',
        'Evangelista', 'Javier', 'Samonte', 'Lacson', 'Medina', 'Morales', 'Padilla', 'Pineda', 'Quiambao', 'Sarmiento',
        'Tan', 'Lim', 'Go', 'Umali', 'Velasco', 'Yap', 'Zamora', 'Abad', 'Bonifacio', 'Concepcion',
        'Espiritu', 'Gatchalian', 'Ignacio', 'Macaraeg', 'Nepomuceno', 'Perez', 'Robles', 'Santiago', 'Agustin', 'Buenaventura',
    ];

    /** @var array<string, true> */
    private array $usedEmails = [];

    /**
     * @return array{first_name: string, middle_name: string|null, last_name: string, suffix: string|null}
     */
    public function person(): array
    {
        $male = mt_rand(0, 1) === 1;
        $first = self::pick($male ? self::MALE : self::FEMALE);
        $last = self::pick(self::SURNAMES);

        do {
            $middle = self::pick(self::SURNAMES);
        } while ($middle === $last);

        return [
            'first_name' => $first,
            'middle_name' => mt_rand(1, 20) === 1 ? null : $middle,
            'last_name' => $last,
            'suffix' => $male && mt_rand(1, 25) === 1 ? self::pick(['Jr.', 'III']) : null,
        ];
    }

    /**
     * "delacruz.johnpaul@scheduler.test", numbered when already taken.
     */
    public function email(string $firstName, string $lastName): string
    {
        $local = Str::of($lastName.'.'.$firstName)->ascii()->lower()->replaceMatches('/[^a-z.]/', '')->toString();
        $email = $local.'@'.CampusData::EMAIL_DOMAIN;

        for ($n = 2; isset($this->usedEmails[$email]); $n++) {
            $email = $local.$n.'@'.CampusData::EMAIL_DOMAIN;
        }

        return $this->reserve($email);
    }

    /**
     * Marks a fixed email (a demo login) as taken.
     */
    public function reserve(string $email): string
    {
        $this->usedEmails[$email] = true;

        return $email;
    }

    /**
     * @template T
     *
     * @param  non-empty-list<T>  $items
     * @return T
     */
    public static function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }
}
