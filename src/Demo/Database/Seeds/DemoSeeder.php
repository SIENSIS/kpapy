<?php

namespace Kpapy\Demo\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Sample data of the kpapy demo (a small library). The demo users are created by the
 * installer (Kpapy\Demo\Installer) and passed in $userIds.
 */
class DemoSeeder extends Seeder
{
    /** @var array<string, int> */
    public array $userIds = [];

    private const AUTHORS = [
        ['Mercè Rodoreda', 'Catalonia', '1908-10-10'],
        ['Joan Sales', 'Catalonia', '1912-11-19'],
        ['Ursula K. Le Guin', 'United States', '1929-10-21'],
        ['Chimamanda Ngozi Adichie', 'Nigeria', '1977-09-15'],
        ['Italo Calvino', 'Italy', '1923-10-15'],
    ];

    private const TAGS = ['novel', 'classic', 'war', 'fantasy', 'science fiction', 'short stories'];

    /**
     * title, author, year, pages, price, tags, audience, summaries (en, ca, es)
     */
    private const BOOKS = [
        ['La plaça del Diamant', 0, 1962, 256, '18.50', [0, 1], ['adults'], ['A young woman in Barcelona before, during and after the Civil War.', 'Una noia a la Barcelona d\'abans, durant i després de la guerra.', 'Una joven en la Barcelona de antes, durante y después de la guerra.']],
        ['Mirall trencat', 0, 1974, 420, '21.00', [0, 1], ['adults'], ['The rise and fall of a wealthy Barcelona family.', 'L\'ascens i la caiguda d\'una família benestant de Barcelona.', null]],
        ['Incerta glòria', 1, 1956, 600, '24.00', [0, 1, 2], ['adults'], ['Friendship, faith and war on the Aragon front.', 'Amistat, fe i guerra al front d\'Aragó.', 'Amistad, fe y guerra en el frente de Aragón.']],
        ['A Wizard of Earthsea', 2, 1968, 205, '12.90', [0, 3], ['teens', 'adults'], ['A young wizard learns the price of power.', 'Un jove mag aprèn el preu del poder.', null]],
        ['The Dispossessed', 2, 1974, 387, '14.50', [0, 4], ['adults'], ['A physicist travels between two very different worlds.', null, null]],
        ['The Left Hand of Darkness', 2, 1969, 304, '13.90', [0, 4, 1], ['adults'], ['An envoy on a planet where gender is not fixed.', null, null]],
        ['Purple Hibiscus', 3, 2003, 307, '11.00', [0], ['teens', 'adults'], ['A teenager grows up under a strict father in Nigeria.', null, null]],
        ['Half of a Yellow Sun', 3, 2006, 433, '13.00', [0, 2], ['adults'], ['Lives changed by the Nigerian Civil War.', null, 'Vidas cambiadas por la guerra civil de Nigeria.']],
        ['Invisible Cities', 4, 1972, 165, '10.50', [5, 1], ['adults'], ['Marco Polo describes impossible cities to Kublai Khan.', 'Marco Polo descriu ciutats impossibles a Kublai Khan.', 'Marco Polo describe ciudades imposibles a Kublai Kan.']],
        ['If on a Winter\'s Night a Traveler', 4, 1979, 260, '11.90', [0], ['adults'], ['A novel about reading a novel.', null, null]],
        ['Marcovaldo', 4, 1963, 128, '9.00', [5], ['kids', 'teens'], ['A worker looks for nature in the industrial city.', null, null]],
        ['Aloma', 0, 1938, 180, '15.00', [0], ['adults'], ['A young woman\'s first love in Barcelona.', 'El primer amor d\'una noia a Barcelona.', null]],
    ];

    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::AUTHORS as [$name, $country, $born]) {
            $this->db->table('kpapy_demo_authors')->insert(['name' => $name, 'country' => $country, 'born' => $born, 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach (self::TAGS as $tag) {
            $this->db->table('kpapy_demo_tags')->insert(['name' => $tag]);
        }

        foreach (self::BOOKS as $i => [$title, $author, $year, $pages, $price, $tags, $audience, $summaries]) {
            $this->db->table('kpapy_demo_books')->insert([
                'title'      => $title,
                'author_id'  => $author + 1,
                'isbn'       => '978-0-' . str_pad((string) (1000 + $i * 37), 5, '0', STR_PAD_LEFT) . '-' . ($i % 10),
                'year'       => $year,
                'pages'      => $pages,
                'price'      => $price,
                'available'  => $i % 4 === 3 ? 0 : 1,
                'audience'   => json_encode($audience),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $id = (int) $this->db->insertID();

            foreach ($tags as $tag) {
                $this->db->table('kpapy_demo_book_tag')->insert(['book_id' => $id, 'tag_id' => $tag + 1]);
            }

            foreach (['en', 'ca', 'es'] as $j => $locale) {
                if ($summaries[$j] !== null) {
                    $this->db->table('kpapy_demo_book_translations')->insert(['book_id' => $id, 'locale' => $locale, 'summary' => $summaries[$j]]);
                }
            }
        }

        // Loans of the reader (some returned) and of the admin
        foreach ([['reader', 1, -20, 'returned'], ['reader', 4, -5, null], ['reader', 9, -2, null], ['admin', 7, -10, null]] as [$user, $book, $days, $returned]) {
            if (! isset($this->userIds[$user])) {
                continue;
            }

            $this->db->table('kpapy_demo_loans')->insert([
                'book_id'     => $book,
                'user_id'     => $this->userIds[$user],
                'loaned_at'   => date('Y-m-d H:i:s', strtotime("{$days} days")),
                'due_at'      => date('Y-m-d', strtotime(($days + 21) . ' days')),
                'returned_at' => $returned === null ? null : date('Y-m-d H:i:s', strtotime(($days + 7) . ' days')),
            ]);
        }
    }
}
