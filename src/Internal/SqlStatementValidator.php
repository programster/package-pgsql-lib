<?php

/*
 * Checks that some SQL is a single statement that's safe to run in a transaction, by walking through it with
 * PostgreSQL's rules for strings, quoted identifiers, dollar quoting and comments, so that a semicolon or a keyword
 * inside one of those is never mistaken for the real thing. It isn't a full parser: only PostgreSQL can tell whether
 * the SQL is entirely valid. It assumes standard_conforming_strings is on, which has been the default since
 * PostgreSQL 9.1. This is internal to the package.
 */

declare(strict_types = 1);

namespace Programster\PgsqlLib\Internal;

use Programster\PgsqlLib\Exceptions\ExceptionInvalidQuery;

/**
 * @internal
 */
final class SqlStatementValidator
{
    private const WHITESPACE = " \t\r\n\f\v";
    private const NUM_KEYWORDS_TO_KEEP = 8;

    // the only characters that matter once the statement's first few words have been read.
    private const SIGNIFICANT_CHARACTERS = "'\"$;()-/";

    private const TRANSACTION_CONTROL = [
        'BEGIN',
        'START',
        'COMMIT',
        'END',
        'ROLLBACK',
        'ABORT',
        'SAVEPOINT',
        'RELEASE',
    ];

    private readonly string $m_sql;
    private readonly int $m_length;
    private readonly string $m_wordCharacters;
    private int $m_position = 0;
    private int $m_parenthesisDepth = 0;
    private int $m_atomicBodyDepth = 0;
    private int $m_highestPlaceholder = 0;
    private bool $m_hasContent = false;
    private bool $m_hasEnded = false;
    private bool $m_copiesToClient = false;
    private bool $m_canSkipWords = false;
    private string $m_previousWord = '';

    /** @var list<string> - the statement's first few words, in upper case, with '' for a quoted identifier. */
    private array $m_keywords = [];


    private function __construct(string $sql)
    {
        $this->m_sql = $sql;
        $this->m_length = strlen($sql);
        $this->m_wordCharacters = self::getWordCharacters();
    }


    /**
     * Check that some SQL is a single statement that can run in a transaction.
     * @param string $sql - the SQL.
     * @param int $numParams - how many values are being given for its placeholders.
     * @return void
     * @throws ExceptionInvalidQuery - if it can't be used, saying why.
     */
    public static function assertValid(string $sql, int $numParams) : void
    {
        $validator = new SqlStatementValidator($sql);
        $validator->scan();
        $validator->assertHasContent();
        $validator->assertDoesNotControlTransaction();
        $validator->assertCanRunInTransaction();
        $validator->assertPlaceholdersMatch($numParams);
    }


    /**
     * Get the characters that can be in an unquoted word, which includes every non-ASCII byte, as PostgreSQL allows.
     * @return string
     */
    private static function getWordCharacters() : string
    {
        /** @var string|null $characters */
        static $characters = null;

        if ($characters === null)
        {
            $highBytes = implode('', array_map('chr', range(128, 255)));
            $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$' . $highBytes;
        }

        return $characters;
    }


    /**
     * Walk through the SQL. Once the first few words have been read, the words in between don't matter, so it jumps
     * straight to the next significant character, which keeps this quick on very large batch inserts. It can't do that
     * after the statement has ended, as anything after that has to be noticed.
     *
     * @return void
     *
     * @throws ExceptionInvalidQuery - if the SQL isn't a single, complete statement.
     */
    private function scan() : void
    {
        while ($this->m_position < $this->m_length)
        {
            if ($this->m_canSkipWords && $this->m_hasEnded === false)
            {
                $this->m_position += strcspn($this->m_sql, self::SIGNIFICANT_CHARACTERS, $this->m_position);
            }

            if ($this->m_position < $this->m_length)
            {
                $this->scanToken();
            }
        }
    }


    private function scanToken() : void
    {
        $character = $this->m_sql[$this->m_position];
        $next = $this->m_sql[$this->m_position + 1] ?? '';

        if (strspn($character, self::WHITESPACE) === 1)
        {
            $this->m_position += strspn($this->m_sql, self::WHITESPACE, $this->m_position);
        }
        elseif ($character === '-' && $next === '-')
        {
            $this->skipLineComment();
        }
        elseif ($character === '/' && $next === '*')
        {
            $this->skipBlockComment();
        }
        elseif ($character === ';')
        {
            $this->handleSemicolon();
        }
        else
        {
            $this->markContent();
            $this->scanContentToken($character);
        }
    }


    private function scanContentToken(string $character) : void
    {
        if ($character === "'" && $this->followsEscapePrefix())
        {
            $this->skipEscapeString();
        }
        elseif ($character === "'")
        {
            $this->skipQuoted("'", 'string');
        }
        elseif ($character === '"')
        {
            $this->keepKeyword('');
            $this->skipQuoted('"', 'quoted identifier');
        }
        elseif ($character === '$' && $this->followsWordCharacter())
        {
            // part of a word that was skipped, e.g. foo$1, rather than a placeholder or dollar quote.
            $this->m_position++;
        }
        elseif ($character === '$')
        {
            $this->scanDollar();
        }
        elseif ($character === '(')
        {
            $this->m_parenthesisDepth++;
            $this->m_position++;
        }
        elseif ($character === ')')
        {
            $this->m_parenthesisDepth = max(0, $this->m_parenthesisDepth - 1);
            $this->m_position++;
        }
        elseif (ctype_digit($character))
        {
            $this->m_position += strspn($this->m_sql, $this->m_wordCharacters, $this->m_position);
        }
        elseif (strspn($character, $this->m_wordCharacters) === 1)
        {
            $this->scanWord();
        }
        else
        {
            $this->m_position++;
        }
    }


    /**
     * Check whether the quote at the current position is preceded by a lone E, making it an E'...' string.
     * @return bool
     */
    private function followsEscapePrefix() : bool
    {
        $previous = ($this->m_position > 0) ? $this->m_sql[$this->m_position - 1] : '';
        $beforeThat = ($this->m_position > 1) ? $this->m_sql[$this->m_position - 2] : ' ';

        return (
               ($previous === 'e' || $previous === 'E')
            && strspn($beforeThat, $this->m_wordCharacters) === 0
        );
    }


    private function followsWordCharacter() : bool
    {
        $previous = ($this->m_position > 0) ? $this->m_sql[$this->m_position - 1] : ' ';
        return strspn($previous, $this->m_wordCharacters) === 1;
    }


    private function handleSemicolon() : void
    {
        if ($this->m_parenthesisDepth === 0 && $this->m_atomicBodyDepth === 0 && $this->m_hasContent)
        {
            $this->m_hasEnded = true;
        }

        $this->m_position++;
    }


    /**
     * Record that the statement has content here, which isn't allowed after the semicolon that ended it.
     * @return void
     * @throws ExceptionInvalidQuery - if the statement has already ended.
     */
    private function markContent() : void
    {
        if ($this->m_hasEnded)
        {
            throw new ExceptionInvalidQuery(
                "A Query must hold exactly one statement, but this holds more: {$this->m_sql}");
        }

        $this->m_hasContent = true;
    }


    private function scanWord() : void
    {
        $length = strspn($this->m_sql, $this->m_wordCharacters, $this->m_position);
        $word = substr($this->m_sql, $this->m_position, $length);
        $this->m_position += $length;

        if (($word === 'e' || $word === 'E') && ($this->m_sql[$this->m_position] ?? '') === "'")
        {
            $this->skipEscapeString();
        }
        else
        {
            $this->handleWord(strtoupper($word));
        }
    }


    private function handleWord(string $word) : void
    {
        $this->keepKeyword($word);

        if ($this->m_keywords[0] === 'COPY' && $this->m_parenthesisDepth === 0)
        {
            $this->m_copiesToClient = ($this->m_copiesToClient || $word === 'STDIN' || $word === 'STDOUT');
        }

        if ($this->isCreatingRoutine())
        {
            $this->trackAtomicBody($word);
        }

        $this->m_previousWord = $word;
    }


    private function keepKeyword(string $word) : void
    {
        if (count($this->m_keywords) < self::NUM_KEYWORDS_TO_KEEP)
        {
            $this->m_keywords[] = $word;

            // COPY and routines still need their later words read, for STDIN/STDOUT and BEGIN ATOMIC.
            $this->m_canSkipWords = (
                   count($this->m_keywords) === self::NUM_KEYWORDS_TO_KEEP
                && $this->m_keywords[0] !== 'COPY'
                && $this->isCreatingRoutine() === false
            );
        }
    }


    /**
     * Check whether this is a CREATE [OR REPLACE] FUNCTION or PROCEDURE, whose body may be BEGIN ATOMIC ... END, with
     * semicolons in it.
     *
     * @return bool
     */
    private function isCreatingRoutine() : bool
    {
        $keywords = $this->m_keywords;
        $routineIndex = (($keywords[1] ?? '') === 'OR') ? 3 : 1;

        return (
               $keywords[0] === 'CREATE'
            && in_array($keywords[$routineIndex] ?? '', ['FUNCTION', 'PROCEDURE'], true)
        );
    }


    private function trackAtomicBody(string $word) : void
    {
        if ($this->m_atomicBodyDepth === 0 && $word === 'ATOMIC' && $this->m_previousWord === 'BEGIN')
        {
            $this->m_atomicBodyDepth = 1;
        }
        elseif ($this->m_atomicBodyDepth > 0 && $word === 'CASE')
        {
            $this->m_atomicBodyDepth++;
        }
        elseif ($this->m_atomicBodyDepth > 0 && $word === 'END')
        {
            $this->m_atomicBodyDepth--;
        }
    }


    private function skipLineComment() : void
    {
        $newLine = strpos($this->m_sql, "\n", $this->m_position);
        $this->m_position = ($newLine === false) ? $this->m_length : $newLine + 1;
    }


    /**
     * Skip a block comment, which in PostgreSQL can have others nested inside it.
     * @return void
     * @throws ExceptionInvalidQuery - if it never ends.
     */
    private function skipBlockComment() : void
    {
        $depth = 1;
        $this->m_position += 2;

        while ($depth > 0)
        {
            $this->m_position += strcspn($this->m_sql, '*/', $this->m_position);
            $pair = substr($this->m_sql, $this->m_position, 2);

            if ($this->m_position >= $this->m_length)
            {
                throw new ExceptionInvalidQuery("This query has a comment that's never closed.");
            }
            elseif ($pair === '/*')
            {
                $depth++;
                $this->m_position += 2;
            }
            elseif ($pair === '*/')
            {
                $depth--;
                $this->m_position += 2;
            }
            else
            {
                $this->m_position++;
            }
        }
    }


    /**
     * Skip a string or quoted identifier, in which the quote is escaped by doubling it.
     *
     * @param string $quote - the quote character.
     *
     * @param string $description - what it is, for the error.
     *
     * @return void
     *
     * @throws ExceptionInvalidQuery - if it never ends.
     */
    private function skipQuoted(string $quote, string $description) : void
    {
        $isInside = true;
        $this->m_position++;

        while ($isInside)
        {
            $close = strpos($this->m_sql, $quote, $this->m_position);

            if ($close === false)
            {
                throw new ExceptionInvalidQuery("This query has a {$description} that's never closed.");
            }

            $isDoubled = (($this->m_sql[$close + 1] ?? '') === $quote);
            $this->m_position = $close + ($isDoubled ? 2 : 1);
            $isInside = $isDoubled;
        }
    }


    /**
     * Skip an E'...' string, in which a backslash also escapes the next character. Like any string, it can carry on
     * after a line break with another quoted part, which is then read the same way.
     *
     * @return void
     *
     * @throws ExceptionInvalidQuery - if it never ends.
     */
    private function skipEscapeString() : void
    {
        $isInside = true;
        $this->m_position++;

        while ($isInside)
        {
            $this->m_position += strcspn($this->m_sql, "\\'", $this->m_position);
            $character = $this->m_sql[$this->m_position] ?? '';

            if ($character === '')
            {
                throw new ExceptionInvalidQuery("This query has a string that's never closed.");
            }
            elseif ($character === '\\' || ($this->m_sql[$this->m_position + 1] ?? '') === "'")
            {
                $this->m_position += 2;
            }
            else
            {
                $this->m_position++;
                $isInside = $this->skipToStringContinuation();
            }
        }
    }


    /**
     * Move into the next part of a string, if one follows after whitespace with a line break in it.
     * @return bool - whether there is one.
     */
    private function skipToStringContinuation() : bool
    {
        $gapLength = strspn($this->m_sql, self::WHITESPACE, $this->m_position);
        $gap = substr($this->m_sql, $this->m_position, $gapLength);

        $hasContinuation = (
               (str_contains($gap, "\n") || str_contains($gap, "\r"))
            && ($this->m_sql[$this->m_position + $gapLength] ?? '') === "'"
        );

        if ($hasContinuation)
        {
            $this->m_position += $gapLength + 1;
        }

        return $hasContinuation;
    }


    /**
     * Handle a $ outside a word: a $1 placeholder, or the start of a dollar-quoted string ($$...$$ or $tag$...$tag$).
     * @return void
     * @throws ExceptionInvalidQuery - if a dollar-quoted string never ends.
     */
    private function scanDollar() : void
    {
        $afterDollar = $this->m_position + 1;
        $numDigits = strspn($this->m_sql, '0123456789', $afterDollar);

        if ($numDigits > 0)
        {
            $number = (int)substr($this->m_sql, $afterDollar, $numDigits);
            $this->m_highestPlaceholder = max($this->m_highestPlaceholder, $number);
            $this->m_position = $afterDollar + $numDigits;
        }
        else
        {
            $tagCharacters = str_replace('$', '', $this->m_wordCharacters);
            $tagLength = strspn($this->m_sql, $tagCharacters, $afterDollar);

            if (($this->m_sql[$afterDollar + $tagLength] ?? '') === '$')
            {
                $delimiter = substr($this->m_sql, $this->m_position, $tagLength + 2);
                $close = strpos($this->m_sql, $delimiter, $this->m_position + $tagLength + 2);

                if ($close === false)
                {
                    $message = "This query has a dollar-quoted string that's never closed.";
                    throw new ExceptionInvalidQuery($message);
                }

                $this->m_position = $close + $tagLength + 2;
            }
            else
            {
                $this->m_position++;
            }
        }
    }


    private function assertHasContent() : void
    {
        if ($this->m_hasContent === false)
        {
            throw new ExceptionInvalidQuery("A Query can't be empty.");
        }
    }


    /**
     * Check that the statement doesn't start or end a transaction, as that would break the one it's running in.
     * @return void
     * @throws ExceptionInvalidQuery - if it does.
     */
    private function assertDoesNotControlTransaction() : void
    {
        $first = $this->m_keywords[0] ?? '';
        $isPreparedTransaction = ($first === 'PREPARE' && ($this->m_keywords[1] ?? '') === 'TRANSACTION');

        if (in_array($first, self::TRANSACTION_CONTROL, true) || $isPreparedTransaction)
        {
            throw new ExceptionInvalidQuery(
                "{$first} can't be used in a Transaction, as the Transaction controls when it starts and ends.");
        }
    }


    /**
     * Check that PostgreSQL allows the statement inside a transaction.
     * @return void
     * @throws ExceptionInvalidQuery - if it doesn't, or the statement needs a COPY data stream.
     */
    private function assertCanRunInTransaction() : void
    {
        if ($this->isDisallowedInTransaction())
        {
            $command = implode(' ', array_slice($this->m_keywords, 0, 2));

            throw new ExceptionInvalidQuery(
                "PostgreSQL doesn't allow {$command} inside a transaction, so run it with query() instead.");
        }

        if ($this->m_copiesToClient)
        {
            throw new ExceptionInvalidQuery(
                "COPY to or from the client can't be used in a Transaction, as it needs its own data stream.");
        }
    }


    private function isDisallowedInTransaction() : bool
    {
        $words = $this->m_keywords;
        $first = $words[0] ?? '';
        $second = $words[1] ?? '';
        $isCreateOrDrop = in_array($first, ['CREATE', 'DROP'], true);
        $isIndex = ($second === 'INDEX' || ($second === 'UNIQUE' && ($words[2] ?? '') === 'INDEX'));
        $movesDatabase = ($second === 'DATABASE' && ($words[3] ?? '') === 'SET' && ($words[4] ?? '') === 'TABLESPACE');

        return (
               $first === 'VACUUM'
            || ($first === 'DISCARD' && $second === 'ALL')
            || ($first === 'ALTER' && ($second === 'SYSTEM' || $movesDatabase))
            || ($isCreateOrDrop && in_array($second, ['DATABASE', 'TABLESPACE'], true))
            || ($isCreateOrDrop && $isIndex && in_array('CONCURRENTLY', $words, true))
            || ($first === 'REINDEX' && count(array_intersect($words, ['DATABASE', 'SYSTEM', 'CONCURRENTLY'])) > 0)
        );
    }


    /**
     * Check that the placeholders match the values. PREPARE and CREATE can hold placeholders that refer to their own
     * parameters, so without any values, those are left alone.
     *
     * @param int $numParams - how many values were given.
     *
     * @return void
     *
     * @throws ExceptionInvalidQuery - if they don't match.
     */
    private function assertPlaceholdersMatch(int $numParams) : void
    {
        $hasOwnParameters = ($numParams === 0 && in_array($this->m_keywords[0] ?? '', ['PREPARE', 'CREATE'], true));

        if ($hasOwnParameters === false && $this->m_highestPlaceholder !== $numParams)
        {
            throw new ExceptionInvalidQuery(
                "This query's placeholders go up to \${$this->m_highestPlaceholder}, but it was given {$numParams}"
                . " value(s)."
            );
        }
    }
}
