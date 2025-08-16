<?php

namespace wescoast;

require_once 'vendor/autoload.php';

use Exception;
use Symfony\Component\Yaml\Yaml;
use drupol\phpermutations\Generators\Combinations as ComboGenerator;
use LucidFrame\Console\ConsoleTable;

class RoundRobin
{
    protected array $playerData = [];
    protected array $partners = [];
    protected array $allValidMatches = [];
    protected array $playedMatches = [];

    protected static array $skillRanks;
    protected static int $teamDiffLimit;
    protected static int $partnerDiffLimit;
    protected static int $numCourts;
    protected static int $genderRankMod;
    protected static int $temperatureMod;

    public function __construct()
    {
        $this->loadConfiguration();
        $this->loadPlayerData();
        $this->initPlayerData(); // may need to be optional if we don't want to lose all games played -- but for now suitable for single runs of the script
    }

    protected function loadConfiguration(): void
    {
        $config = Yaml::parseFile('config.yml');

        foreach ($config as $key => $value) {
            switch ($key) {
                case 'TEAM_DIFF_LIMIT':
                    self::$teamDiffLimit = $value;
                    break;
                case 'PARTNER_DIFF_LIMIT':
                    self::$partnerDiffLimit = $value;
                    break;
                case 'NUM_COURTS':
                    self::$numCourts = $value;
                    break;
                case 'SKILL_SCORES':
                    self::$skillRanks = $value;
                    break;
                case 'GENDER_SCORE_MODIFIER':
                    self::$genderRankMod = $value;
                    break;
                case 'TEMPERATURE':
                    self::$temperatureMod = $value;
                    break;
            }
        }
    }

    protected function loadPlayerData(): void
    {
        if (($handle = fopen("players2024.csv", "r")) !== FALSE) {
            $headers = fgetcsv($handle, 1000, ',', '"', '');
            while (($data = fgetcsv($handle, 1000, ',', '"', '')) !== FALSE) {
                // This check handles potential errors where a row might not have the same number of columns as headers.
                if (count($headers) == count($data)) {
                    $this->playerData[] = array_combine($headers, $data);
                }
            }
            fclose($handle);
        }
    }

    protected function initPlayerData(): void
    {
        foreach ($this->playerData as &$player) { // Use reference to modify in place
            $player['games_played'] = 0;
            $player['skill_score'] = $this->playerScore($player['peg_colour'], $player['gender']);
        }
    }

    protected function generateValidMatches(): array
    {
        if (!count($this->partners)) {
            $playerPool = array_map(fn($p) => ['name' => $p['peg_name'], 'score' => $p['skill_score']], $this->playerData);
            $teams = new ComboGenerator($playerPool, 2);
            $this->partners = $teams->toArray();
        }

        $validMatches = [];

        foreach ($this->partners as $team1) {
            $team1_names = array_column($team1, 'name');

            foreach ($this->partners as $team2) {

                // Ensure no player is on both teams
                $team2_names = array_column($team2, 'name');
                if (array_intersect($team1_names, $team2_names)) {
                    continue;
                }

                // this accounts for the score difference of 2 teams... e.g. 40 & 70 vs 60 & 60  or  40 & 100 vs 30 & 100,
                // but we should also check for a greater score difference between partners too
                if (abs($team1[0]['score'] - $team1[1]['score']) > self::$partnerDiffLimit ||
                    abs($team2[0]['score'] - $team2[1]['score']) > self::$partnerDiffLimit) {
                    continue;
                }

                $tScore1 = $team1[0]['score'] + $team1[1]['score'];
                $tScore2 = $team2[0]['score'] + $team2[1]['score'];

                if (abs($tScore1 - $tScore2) <= self::$teamDiffLimit) {

                    $t1Hash = md5(print_r($team1_names,true));
                    $t2Hash = md5(print_r($team2_names,true));

                    $hashMash = [$t1Hash, $t2Hash];
                    sort($hashMash);
                    $hashMashStr = implode(":", $hashMash);

                    $validMatches[$hashMashStr] = array_merge($team1_names, $team2_names);
                }
            }
        }

        // Return unique matches and give them a good shuffle whilst we're at it...
        return $this->shuffleAssoc($validMatches);
    }

    protected function shuffleAssoc($list)
    {
        if (!is_array($list)) {
            return $list;
        }

        $keys = [];
        $origKeys = array_keys($list);
        shuffle($origKeys);

        foreach ($origKeys as $key) {
            $keys[] = strrev($key);
        }
        shuffle($keys);

        $random = [];
        foreach ($keys as $key) {
            $key = strrev($key);
            $random[$key] = $list[$key];
        }
        return $random;
    }

    public function execMenu(): void
    {
        $loops = 0;
        $gamesGenCount = 1;
        $matchLog = []; // Log for saving

        while (true) {
            echo PHP_EOL;
            if (!$loops) {
                echo "==========================================".PHP_EOL.
                     "Welcome to the Round Robin Game Scheduler!".PHP_EOL.
                     "==========================================".PHP_EOL.PHP_EOL;
            }
            echo "Press Enter to generate game $gamesGenCount.".PHP_EOL.
                 "Press 1 to view the player statistics.".PHP_EOL.
                 "Press 2 to output match history to a JSON file.".PHP_EOL.
                 "Press 3 to exit.".PHP_EOL;

            $choice = readline("Your choice: ");

            switch ($choice) {
                case '1':
                    echo PHP_EOL."Player statistics:".PHP_EOL;
                    print_r($this->playerData);
                    break;

                case '2':
                    echo PHP_EOL."Saving match history to JSON...".PHP_EOL;
                    $this->saveMatches($matchLog, "logs.json");
                    break;

                case '3':
                    echo PHP_EOL."Exiting the game scheduler. Goodbye!".PHP_EOL;
                    return;

                default:
                    $matches = $this->scheduleRound();
                    $matchLog[] = $matches;

                    // Update games_played count and played_matches history
                    foreach ($matches as $match) {
                        $this->playedMatches[] = $match;

                        foreach ($match as $playerName) {
                            // Find player in playerData and increment their game count
                            foreach ($this->playerData as &$p) {
                                if ($p['peg_name'] === $playerName) {
                                    $p['games_played']++;
                                    break;
                                }
                            }
                            unset($p);
                        }
                    }

                    $this->printMatches($matches);

                    $gamesGenCount++;
                    break;
            }

            $loops++;
        }
    }

    public function scheduleRound(): array
    {
        if (!count($this->allValidMatches)) {
            echo PHP_EOL."Generating valid matches...".PHP_EOL;
            $this->allValidMatches = $this->generateValidMatches();
        }

        if (count($this->playedMatches) == count($this->allValidMatches)) {
            echo PHP_EOL."All matches played, resetting to zero...";
            $this->playedMatches = [];
        }

        // Filter out matches that have already been played
        $playedMatches = $this->playedMatches;
        $unplayedMatches = array_filter($this->allValidMatches, function($match) use ($playedMatches) {
            return !in_array($match, $playedMatches);
        }, ARRAY_FILTER_USE_BOTH);

        echo PHP_EOL.'count of allValidMatches: '.count($this->allValidMatches).PHP_EOL;
        echo 'count of unplayedMatches: '.count($unplayedMatches).PHP_EOL;
        echo 'count of playedMatches: '.count($this->playedMatches).PHP_EOL;

        // Create a quick lookup map of player names to their games_played count.
        $gamesPlayedMap = array_column($this->playerData, 'games_played', 'peg_name');

        // Matches with a lower sum of games_played among their players will have higher priority (come first).
        usort($unplayedMatches, function ($matchA, $matchB) use ($gamesPlayedMap) {
            $scoreA = 0;
            foreach ($matchA as $player) {
                $scoreA += $gamesPlayedMap[$player] ?? 0;
            }

            $scoreB = 0;
            foreach ($matchB as $player) {
                $scoreB += $gamesPlayedMap[$player] ?? 0;
            }

            // The spaceship operator (<=>) sorts in ascending order.
            // A lower score (fewer games played) will result in a negative value, placing it earlier in the sorted array.
            return $scoreA <=> $scoreB;
        });

        $chosenMatches = [];
        $playersInThisRound = [];
        foreach ($unplayedMatches as $key => $match) {
            // Stop if we have enough matches for the courts
            if (count($chosenMatches) >= self::$numCourts) {
                break;
            }

            // Check if any player in the potential match is already playing this round
            if (array_intersect($match, $playersInThisRound)) {
                continue;
            }

            // If the match is valid, add it and the players to our lists
            $chosenMatches[$key] = $match;
            $playersInThisRound = array_merge($playersInThisRound, $match);
        }

        return $chosenMatches;
    }

    public function printMatches(array $matches): void
    {
        if (empty($matches)) {
            echo PHP_EOL."No valid new matches could be generated for this round.".PHP_EOL;
            return;
        }

        $table = new ConsoleTable;
        $table ->setHeaders(["Court", "\u{2192} T1 Player1", "\u{2192} T1 Player2", '  ', "\u{2192} T2 Player1", "\u{2192} T2 Player2"])
               ->hideBorder();

        $playerColours = array_column($this->playerData, 'peg_colour', 'peg_name');
        $playerGenders = array_column($this->playerData, 'gender', 'peg_name');

        $i=0;
        $playerNames = [];

        foreach ($matches as $match) {
            [$p1, $p2, $p3, $p4] = $match;
            $playerNames[] = [$p1, $p2, $p3, $p4];

            $p1G = strtolower($playerGenders[$p1]) == 'f' ? "\u{2640}" : "\u{2642}";
            $p2G = strtolower($playerGenders[$p2]) == 'f' ? "\u{2640}" : "\u{2642}";
            $p3G = strtolower($playerGenders[$p3]) == 'f' ? "\u{2640}" : "\u{2642}";
            $p4G = strtolower($playerGenders[$p4]) == 'f' ? "\u{2640}" : "\u{2642}";

            $team1_p1_info = "$p1G $p1 ($playerColours[$p1])";
            $team1_p2_info = "$p2G $p2 ($playerColours[$p2])";
            $team2_p3_info = "$p3G $p3 ($playerColours[$p3])";
            $team2_p4_info = "$p4G $p4 ($playerColours[$p4])";

            $table->addRow([($i + 1), $team1_p1_info, $team1_p2_info, 'vs', $team2_p3_info, $team2_p4_info]);
            $i++;
        }

        echo PHP_EOL;
        $table->display();

        $allPlayerNames = array_column($this->playerData, 'peg_name');
        $playingPlayerNames = array_merge(...$playerNames);
        $sittingOut = array_diff($allPlayerNames, $playingPlayerNames);

        if (!empty($sittingOut)) {
            echo PHP_EOL."Players sitting out this round:".PHP_EOL;
            echo implode(', ', $sittingOut) . PHP_EOL;
        }
    }

    public function saveMatches(array $matchLog, string $outputFile): void
    {
        $log = [];
        $allPlayerNames = array_column($this->playerData, 'peg_name');

        $round = ['matches' => [], 'waiting' => []];

        foreach ($matchLog as $roundMatches) {
            $playingPlayerNames = [];
            foreach ($roundMatches as $match) {
                $game = ['T1' => [$match[0], $match[1]], 'T2' => [$match[2], $match[3]]];
                $round['matches'][] = $game;
                $playingPlayerNames = array_merge($playingPlayerNames, $match);
            }
            $round['waiting'] = array_values(array_diff($allPlayerNames, $playingPlayerNames));
            $log[] = $round;
        }

        file_put_contents($outputFile, json_encode($log, JSON_PRETTY_PRINT));
        echo "Matches saved to $outputFile".PHP_EOL;
    }

    public function playerScore(string $colour, string $gender): int
    {
        $score = self::$skillRanks[$colour] ?? 0;
        if (strtolower($gender) === 'f') {
            $score += self::$genderRankMod;
        }
        try {
            $score += random_int(0, self::$temperatureMod); // optional temperature could be used to randomly increase the rank of a player slightly -- default is: 0(min),0(max)
        } catch (Exception $e) {
            echo "playerScore | ".$e->getMessage().PHP_EOL;
        }
        return $score;
    }
}
