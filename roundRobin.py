from itertools import combinations
import json
import numpy as np
import os
import random

from ortools.sat.python import cp_model
import pandas as pd
from tabulate import tabulate
import yaml

from utils import loading_spinner

with open("config.yml", "r") as file:
    config = yaml.safe_load(file)
    # config = yaml.load(file, Loader=yaml.SafeLoader)
    print("Loaded config:", config)


SCORE_DIFF_LIMIT = config.get("SCORE_DIFF_LIMIT")  # Default to 40 if not specified in config
NUM_COURTS = config.get("NUM_COURTS", 5)  # Default to 5 if not specified in config
SKILL_SCORES = config.get("SKILL_SCORES")
GENDER_SCORE_MODIFIER = config.get("GENDER_SCORE_MODIFIER", -10)
TEMPERATURE = config.get("TEMPERATURE", 0)
MAX_TIME = config.get("MAX_TIME", None)  # Default to finding optimal solution if not specified
DEBUG = config.get("DEBUG", False)


def main():

    def generate_valid_matches(players):
        # players: list of (player_name, score)
        valid_matches = []
        players = [(p["peg_name"], p["skill_score"]) for p in players]
        for team1 in combinations(players, 2):
            for team2 in combinations(players, 2):
                if set(team1) & set(team2):  # if intersection is not empty, skip
                    continue
                score1 = team1[0][1] + team1[1][1]
                score2 = team2[0][1] + team2[1][1]
                if abs(score1 - score2) <= SCORE_DIFF_LIMIT:
                    match = tuple([p[0] for p in team1 + team2])
                    # print(f"Match: {match} with scores {score1} vs {score2}")
                    valid_matches.append(match)
        # Remove duplicates (same 4 players, different order)
        valid_matches = list(set(valid_matches))
        return valid_matches

    def schedule_round(players, courts, played_matches, play_priority):
        """
        players: list of dicts with player info
        courts: int (max matches this round)
        played_matches: set of frozenset(player_ids) that already happened
        play_priority: dict {player_id: priority score}
        """
        matches = generate_valid_matches(players)
        model = cp_model.CpModel()

        # Create variables: 1 if match is chosen
        match_vars = {}
        for m in matches:
            match_vars[m] = model.NewBoolVar(f"match_{'_'.join(map(str,m))}")

        names = [p["peg_name"] for p in players]  # list of player names
        # Constraint: each player plays at most once per round
        for name in names:
            model.Add(sum(match_vars[m] for m in matches if name in m) <= 1)

        # Constraint: limit by court count
        model.Add(sum(match_vars[m] for m in matches) <= courts)

        # Avoid repeats: forbid matches already played
        for m in matches:
            if frozenset(m) in played_matches:
                model.Add(match_vars[m] == 0)

        # Objective: prioritize players who haven't played recently
        model.Maximize(
            sum(sum(play_priority[p] for p in m) * match_vars[m] for m in matches)
        )

        # Solve
        solver = cp_model.CpSolver()

        # Faster with these settings for small models
        solver.parameters.num_search_workers = 1
        solver.parameters.cp_model_presolve = False

        solver.parameters.log_search_progress = DEBUG

        if MAX_TIME:
            solver.parameters.max_time_in_seconds = (
                MAX_TIME  # Optimal solution relatively fast for N <= 40
            )

        done_spinner = loading_spinner("Solving with CP-SAT")
        status = solver.Solve(model)
        done_spinner()

        # Optimal is provable best solution. Feasible is a solution found within time limit.
        if status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
            chosen_matches = [m for m in matches if solver.Value(match_vars[m])]
            return chosen_matches
        else:
            return []

    def playerScore(colour, gender, temperature=TEMPERATURE):

        score = SKILL_SCORES[colour]
        if gender == "f":
            score += GENDER_SCORE_MODIFIER

        score += random.randint(0, temperature)

        return score

    # def teamScore(team):
    #     score = team[0][1] + team[1][1]
    #     if (PENALISE_PARTNER_SKILL_GAP):
    #         score -= abs(team[0][1] - team[1][1]) / 2
    #     return score

    def print_matches(df, matches):
        """
        matches: list of tuples (name1, name2, name3, name4)
        """

        # Prepare table rows
        def get_score(p):
            return df.loc[df["peg_name"] == p, ["peg_colour", "skill_score"]].values[0]

        rows = []
        for p1, p2, p3, p4 in matches:
            team1 = f"{p1} {get_score(p1)} & {p2} {get_score(p2)}"
            team2 = f"{p3} {get_score(p3)} & {p4} {get_score(p4)}"
            rows.append([team1, "vs", team2])

        # Print table
        print(tabulate(rows, headers=["Team 1", "", "Team 2"], tablefmt="grid"))

        # Playerrs sitting out
        sitting_out = set(df["peg_name"]) - set(sum(matches, ()))
        if sitting_out:
            print("\nPlayers sitting out this round:")
            print(", ".join(sitting_out))

    def save_matches(df, match_log, output_file="matches.json"):
        """
        Save matches to a JSON file.
        """
        log = []
        for matches in match_log:
            round = {"matches": [], "waiting": []}
            for match in matches:
                game = {}
                game["T1"] = [match[0], match[1]]
                game["T2"] = [match[2], match[3]]
                round["matches"].append(game)

            sitting_out = set(df["peg_name"]) - set(sum(matches, ()))
            round["waiting"] = list(sitting_out)
            log.append(round)

        with open(output_file, "w") as f:
            json.dump(log, f, indent=4)
        print(f"Matches saved to {output_file}")


    def generate_game(df, players, game_count):
        play_priority = df.set_index("peg_name")[
            "play_priority"
        ].to_dict()  # get play priority from df
        matches = schedule_round(players, courts, played_matches, play_priority)

        # add 1 to games played for each player in matches
        for match in matches:
            for player in match:
                df.loc[
                    df["peg_name"].str.contains(player.split("_")[0]), "games_played"
                ] += 1

        # add matches to played_matches
        for match in matches:
            played_matches.add(frozenset(match))
        print(f"Removed {len(played_matches)} matches from pool.")

        return matches

    ### Program Start ###

    game_count = 0
    df = pd.read_csv("players2024.csv")
    df["games_played"] = 0
    df["play_priority"] = 1  # Initialize play priority
    df["skill_score"] = df.apply(
        lambda row: playerScore(row["peg_colour"], row["gender"]), axis=1
    )
    df = df[:25]

    # convert df into a list of dicts
    players = df.to_dict("records")

    courts = NUM_COURTS
    played_matches = set()  # no history yet


    match_log = []  # to store all matches played

    while True:
        choice = input(
            """
            Welcome to the Round Robin Game Scheduler!
            Press Enter to start generating games.
            Press 1 to view the player statistics.
            Press 2 to output match history to a JSON file.
            Press 3 to exit.
            """
        )

        matches = []

        match choice:
            case "1":
                print("\nPlayer statistics:")
                print(tabulate(df, headers="keys", tablefmt="grid", showindex=False))
            case "2":
                output_file = "logs.json"
                print("\nSaving match history to JSON...")
                save_matches(match_log, output_file)
            case "3":
                print("Exiting the game scheduler. Goodbye!")
                return
            case _:
                game_count += 1
                input(f"\nPress Enter to generate game {game_count}...\n")
                matches = generate_game(df, players, game_count)
                match_log.append(matches)
                print_matches(df, matches)

                # update play priority based on games played
                df["play_priority"] = (
                    1 - df["games_played"] / game_count
                )
                # if didn't play in previous match, priority is 1
                sitting_out = set(df["peg_name"]) - set(sum(matches, ()))
                for player in sitting_out:
                    df.loc[df["peg_name"] == player, "play_priority"] = 1

                output_file = "player_stats.csv"
                df.sort_values(by="play_priority", ascending=False).to_csv(
                    f"{output_file}", index=False
                )
                print(f"Player statistics saved to {output_file}")


if __name__ == "__main__":
    main()
