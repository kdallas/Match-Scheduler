# Badminton-Round-Robin
Round Robin match generator for 2v2 badminton matches accounting for skill differences and aiming for equal number of games played.


# Explanation
## Why
Generating round robin pairings becomes an increasingly complicated challenge the more constraints are considered. The main factors are player skill level, and time players have been spent waiting. To account for these factors, Integer Programming is used to find solutions given these contraints. The CP-SAT solver from Google's OR-Tools suite is used as its an efficient and open source library.

## Contraints
Every player is given a skill score based on their peg colour and gender. A Match is only considered valid if the difference in the sum of skill scores on both sides of the court is less than a specified limit. This ensures only balanced matches. 

Let M be the set of all valid Matches.

>If a Match m is played this round, it has the value 1, otherwise it is 0.

$$
\forall m \in M, x_m = \begin{cases}
    1 & \text{if match m is chosen this round}  \\
    0 & \text{otherwise}
\end{cases}
$$

>Each player can only be in max 1 match at a time

$$
\forall p, \sum_{m \ni p}{x_m \le 1 }
$$

>Limit maximum number of games by number of courts (C)

$$
\sum_{m \in M}{x_m \le C}
$$

>Forbid repeat matches

$$x_m = 0$$

>Prioritise players that didn't play the previous round and players that have the lowest number of games played. This is done by assigning a priority score for each player based on number of played games (n) and the total number of rounds played so far (R).

$$
priority(p) = \begin{cases}
    1               & \text{If p not played in previous round}  \\
    1- \frac{n}{R}  & \text{otherwise}
\end{cases}
$$

>The algorithm has the objective of maximising the total priority score in all the matches being played in a round.

$$
\max \left( \sum_{m \in M} \left( \sum_{p \in m} \text{priority}(p) \right) \cdot x_m \right) 
$$

# How to Run
- Ensure python is installed.
- Install the requirements into your environment of choice. 

```
pip install -r requirements.txt
```

- Set options in config.yml
- Load player data into players2024.csv
- Start the program

```
python roundRobin.py
```

- Use options to continously generate matches while avoiding repeats
- Keep running stats of how many games each player has played
- Save a log of the matches played
