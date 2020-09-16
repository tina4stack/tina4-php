<?php

class TicTacToe
{
    private const board = ["-", "-", "-", "-", "-", "-", "-", "-", "-"];
    private const tokens = ["x", "o"];
    private const wins = [[0, 1, 2], [3, 4, 5], [6, 7, 8], [0, 3, 6], [1, 4, 7], [2, 5, 8], [0, 4, 8], [2, 4, 6]];

    static function playGame()
    {
        $html = "<pre>";

        //$html .= print_r (self::board, 1);

        $board = self::board;
        /*
         * 012
         * 345
         * 678
         */
        //$board[1] = 'x';
        //$board[4] = 'o';
        //$board[5] = 'x';
        //$board[2] = 'o';


        //for ($i = 0; $i < 8; $i++) {
        //$move = self::getBestMove($board, $computerStart);
        //if ($computerStart) {
        //$board[$move] = 'x';
        //} else {
        //$board[$move] = 'o';
        //}
        //$html .= self::drawBoard($board, "RESULT");

        //$computerStart = !$computerStart;
        //}

        $computerPlays = true;

        $move = self::getBestMove($board, $computerPlays);
        $board[$move] = 'x';
        $board[1] = 'o';
        $move = self::getBestMove($board, $computerPlays);
        $board[$move] = 'x';
        $board[6] = 'o';
        $move = self::getBestMove($board, $computerPlays);
        $board[$move] = 'x';
        $board[5] = 'o';
        $move = self::getBestMove($board, $computerPlays);
        $board[$move] = 'x';


        //if ($computerPlays) {
        //$move = self::getBestMove($board, $computerPlays);
        //$board[$move] = 'x';
        //$computerPlays = false;
        //} else {

        //}


        $html .= self::drawBoard($board, "RESULT");

        //$html .= "score is ".self::miniMax($board, 0, true);

        //$html .= self::getBoardValue($board, self::tokens[0], self::tokens[1]);


        $html .= "</pre>";
        return $html;
    }

    static function getBestMove($board, $computer)
    {
        $bestMove = 0;
        $bestScore = -10000;
        for ($i = 0; $i < count($board); $i++) {
            if ($board[$i] == "-") {
                $board[$i] = ($computer) ? self::tokens[0] : self::tokens[1];
                //echo self::drawBoard($board, "Next Move");
                //$score = self::getBoardValue($board, ($computer) ? self::tokens[0] : self::tokens[1], (!$computer) ? self::tokens[0] : self::tokens[1]);
                $score = self::miniMax($board, 0, !$computer) . "<br>";
                $board[$i] = "-";
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMove = $i;
                }
            }
        }
        return $bestMove;
    }

    static function miniMax($board, $depth, $isComputer)
    {
        $score = self::getBoardValue($board, self::tokens[0], self::tokens[1]);

        if ($score !== 0) {
            //echo self::drawBoard($board, "WIN {$depth}");
            return $score;
        }

        if (self::boardIsFull($board)) {
            return 0;
        }

        if ($isComputer) {
            $bestScore = -1000000000;
            for ($i = 0; $i < count($board); $i++) {
                if ($board[$i] == "-") {
                    $board[$i] = self::tokens[0];
                    $score = self::miniMax($board, $depth + 1, false);
                    $board[$i] = "-";
                    $bestScore = max($score, $bestScore);
                }
            }
            return $bestScore;
        } else {
            $bestScore = 1000000000;
            for ($i = 0; $i < count($board); $i++) {
                if ($board[$i] == "-") {
                    $board[$i] = self::tokens[1];
                    $score = self::miniMax($board, $depth + 1, true);
                    $board[$i] = "-";
                    $bestScore = min($score, $bestScore);
                }
            }
            return $bestScore;
        }
    }

    /**
     * @param $board
     * @param $computer
     * @param $player
     * @return int
     */
    static function getBoardValue($board, $computer, $player)
    {
        //computer will be max
        //player will be min
        //win is 10 for computer
        //loss is -10 for computer
        $winScore = 0;
        for ($i = 0; $i < count(self::wins); $i++) {
            $winScenario = self::wins[$i];
            $iScoreComputer = 0;
            $iScorePlayer = 0;
            for ($j = 0; $j < count($winScenario); $j++) {
                //check if computer gets a row
                if ($board[$winScenario[$j]] == $computer) {
                    $iScoreComputer++;
                }
                //check if player gets a row
                if ($board[$winScenario[$j]] == $player) {
                    $iScorePlayer++;
                }

                if ($iScoreComputer == count($winScenario)) {
                    $winScore = 10;
                    return $winScore;
                }

                if ($iScorePlayer == count($winScenario)) {
                    $winScore = -10;
                    return $winScore;
                }
            }
        }
        return $winScore;
    }

    static function boardIsFull($board)
    {
        for ($i = 0; $i < count($board); $i++) {
            if ($board[$i] === "-") {
                return false;
            }
        }
        return true;
    }

    static function drawBoard($board, $title)
    {
        $html = "<table border='1'>";
        $html .= "<tr><th colspan='3'>{$title}</th></tr>";
        $html .= "<tr>
                    <td>{$board[0]}</td>
                    <td>{$board[1]}</td>
                    <td>{$board[2]}</td>
                 </tr>";
        $html .= "<tr>
                    <td>{$board[3]}</td>
                    <td>{$board[4]}</td>
                    <td>{$board[5]}</td>
                 </tr>";
        $html .= "<tr>
                    <td>{$board[6]}</td>
                    <td>{$board[7]}</td>
                    <td>{$board[8]}</td>
                 </tr>";
        $html .= "</table>";
        return $html;
    }

}