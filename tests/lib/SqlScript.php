<?php
/*
 * SqlScript - runs a .sql file the way DBeaver's "Execute script" (Alt+X) does: statement by statement, split on ";"
 * outside quotes, backticks and comments, every result set read back. Used by suite 14 on the migration scripts.
 *
 *   SqlScript::run($pdo, $sql)            every statement; an error is recorded and the run goes on - what DBeaver does
 *                                         when someone answers its error dialog with "Ignore All" (the worst case)
 *   SqlScript::section($sql, 'PART A', 'END OF PART A')   the text between two heading lines
 *   SqlScript::uncomment($sql, 'first line', 'last line')  a commented-out block, with the leading "-- " taken off
 */
final class SqlScript
{
    /** @return string[] the statements, without comments, in order */
    public static function split(string $sql): array
    {
        $out = [];
        $cur = '';
        $n = strlen($sql);
        for ($i = 0; $i < $n; $i++) {
            $c = $sql[$i];
            $next = $i + 1 < $n ? $sql[$i + 1] : '';
            if ($c === '-' && $next === '-' && ($i + 2 >= $n || in_array($sql[$i + 2], [' ', "\t", "\r", "\n"], true))) {
                while ($i < $n && $sql[$i] !== "\n") $i++;               // "-- " comment to the end of the line
                $cur .= "\n";
                continue;
            }
            if ($c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $n : $end + 1;
                $cur .= ' ';
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $q = $c;
                $cur .= $c;
                for ($i++; $i < $n; $i++) {
                    $d = $sql[$i];
                    $cur .= $d;
                    if ($d === '\\' && $q !== '`' && $i + 1 < $n) { $cur .= $sql[++$i]; continue; }
                    if ($d === $q) {
                        if ($i + 1 < $n && $sql[$i + 1] === $q) { $cur .= $sql[++$i]; continue; }   // '' inside '...'
                        break;
                    }
                }
                continue;
            }
            if ($c === ';') {
                if (trim($cur) !== '') $out[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        if (trim($cur) !== '') $out[] = trim($cur);
        return $out;
    }

    /**
     * Every statement in turn. Returns ['errors' => [[statement, message]], 'results' => [[statement, rows]]].
     * $stopOnError = true is what DBeaver does when the error dialog is answered with "Stop".
     */
    public static function run(PDO $db, string $sql, bool $stopOnError = false): array
    {
        $errors = $results = [];
        foreach (self::split($sql) as $stmt) {
            try {
                $st = $db->query($stmt);
                if ($st->columnCount() > 0) $results[] = [$stmt, $st->fetchAll(PDO::FETCH_ASSOC)];
                $st->closeCursor();
            } catch (PDOException $e) {
                $errors[] = [$stmt, $e->getMessage()];
                if ($stopOnError) break;
            }
        }
        return ['errors' => $errors, 'results' => $results];
    }

    /** the lines from the one containing $from to the one containing $to (both included) */
    public static function section(string $sql, string $from, string $to): string
    {
        $lines = preg_split('/\R/', $sql);
        $start = $end = null;
        foreach ($lines as $i => $l) {
            if ($start === null && strpos($l, $from) !== false) $start = $i;
            elseif ($start !== null && strpos($l, $to) !== false) { $end = $i; break; }
        }
        if ($start === null || $end === null) throw new RuntimeException("section '$from' … '$to' not found");
        return implode("\n", array_slice($lines, $start, $end - $start + 1));
    }

    /** a commented-out block (every line "-- …") from the line holding $first to the line holding $last, uncommented */
    public static function uncomment(string $sql, string $first, string $last): string
    {
        $block = self::section($sql, $first, $last);
        $out = [];
        foreach (preg_split('/\R/', $block) as $l) {
            if (preg_match('/^--\s?(.*)$/', $l, $m)) $out[] = $m[1];
        }
        return implode("\n", $out);
    }

    /**
     * What DBeaver's "Execute script" (Alt+X) trips over although MySQL itself does not: a blank (or whitespace-only) line INSIDE a statement
     * - DBeaver ends the statement there - a line that starts with @ (a DBeaver command) and a question mark (a parameter to ask for).
     * Returns a list of readable problems, [] when the file runs in DBeaver the way it runs here. The statements are cut on ';' outside
     * quotes and comments, the comment lines inside a statement are not counted as blank.
     */
    public static function dbeaverProblems(string $sql): array
    {
        $problems = [];
        $lines = preg_split('/\R/', $sql);
        foreach ($lines as $i => $l) {
            $t = ltrim($l);
            if (str_starts_with($t, '--')) continue;
            if (str_starts_with($t, '@')) $problems[] = 'line ' . ($i + 1) . ' starts with @';
            if (str_contains($l, '?')) $problems[] = 'line ' . ($i + 1) . ' holds a question mark';
        }
        $n = strlen($sql);
        $start = 0;
        for ($i = 0; $i < $n; $i++) {
            $c = $sql[$i];
            $next = $i + 1 < $n ? $sql[$i + 1] : '';
            if ($c === '-' && $next === '-' && ($i + 2 >= $n || in_array($sql[$i + 2], [' ', "\t", "\r", "\n"], true))) {
                while ($i < $n && $sql[$i] !== "\n") $i++;
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $q = $c;
                for ($i++; $i < $n; $i++) {
                    if ($sql[$i] === '\\' && $q !== '`' && $i + 1 < $n) { $i++; continue; }
                    if ($sql[$i] === $q) {
                        if ($i + 1 < $n && $sql[$i + 1] === $q) { $i++; continue; }
                        break;
                    }
                }
                continue;
            }
            if ($c === ';') {
                $problems = array_merge($problems, self::blankInside(substr($sql, $start, $i - $start), substr_count($sql, "\n", 0, $start)));
                $start = $i + 1;
            }
        }
        return $problems;
    }

    /** a blank line between the first word of a statement and its end ($firstLine = the lines before the statement's text) */
    private static function blankInside(string $stmt, int $firstLine): array
    {
        $out = [];
        $rows = preg_split('/\R/', $stmt);
        $seen = false;
        $pendingBlank = null;
        foreach ($rows as $k => $row) {
            $t = trim($row);
            if ($t === '' ) { if ($seen) $pendingBlank ??= $firstLine + $k + 1; continue; }
            if (str_starts_with($t, '--')) continue;
            if ($pendingBlank !== null) { $out[] = 'line ' . $pendingBlank . ' is blank inside a statement'; $pendingBlank = null; }
            $seen = true;
        }
        return $out;
    }

    /** the rows of the first result set that has all these columns */
    public static function resultWith(array $run, array $columns): ?array
    {
        foreach ($run['results'] as [$stmt, $rows]) {
            if (!$rows) continue;
            if (!array_diff($columns, array_keys($rows[0]))) return $rows;
        }
        return null;
    }
}
