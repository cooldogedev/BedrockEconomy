<?php

/**
 *  Copyright (c) 2022 cooldogedev
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 *  SOFTWARE.
 */

declare(strict_types=1);

namespace cooldogedev\BedrockEconomy\query\mysql\player;

use cooldogedev\BedrockEconomy\query\ErrorCodes;
use cooldogedev\BedrockEconomy\transaction\Transaction;
use cooldogedev\BedrockEconomy\transaction\types\UpdateTransaction;
use cooldogedev\libSQL\query\MySQLQuery;
use mysqli;

final class MySQLUpdateQuery extends MySQLQuery
{
    public function __construct(protected UpdateTransaction $transaction)
    {
    }

    public function onRun(mysqli $connection): void
    {
        $username = strtolower($this->getTransaction()->getTarget());

        // Fail if the player doesn't exist, if the balance cap is exceeded or if the balance is insufficient
        if (!$this->verifyAccount($connection, $username)) {
            return;
        }

        // Prepare the update query
        $statement = $connection->prepare($this->getQuery());
        $statement->bind_param("s", $username);
        $statement->execute();

        $successful = $statement->affected_rows > 0;

        $statement->close();

        // Fail if no changes were made
        if (!$successful) {
            $this->setError(ErrorCodes::ERROR_CODE_NO_CHANGES_MADE);
            $this->setResult(false);
            return;
        }

        // Set the result
        $this->setResult(true);
    }

    public function getTransaction(): UpdateTransaction
    {
        return $this->transaction;
    }

    protected function verifyAccount(mysqli $connection, string $username): bool
    {
        $type = $this->getTransaction()->getType();
        $value = $this->getTransaction()->getValue();
        $balanceCap = $this->getTransaction()->getBalanceCap();

        $statement = $connection->prepare($this->getRetrieveQuery());
        $statement->bind_param("s", $username);
        $statement->execute();

        $data = $statement->get_result()?->fetch_assoc();
        $isVerified = $data !== null;
        $balance = $data["balance"] ?? null;

        $statement->close();

        if (!$isVerified) {
            $this->setError(ErrorCodes::ERROR_CODE_ACCOUNT_NOT_FOUND);
            $this->setResult(false);
        }


        if ($isVerified && $balance !== null && $balanceCap !== null && $type === Transaction::TRANSACTION_TYPE_INCREMENT && $balance >= $balanceCap) {
            $isVerified = false;

            $this->setError(ErrorCodes::ERROR_CODE_BALANCE_CAP_EXCEEDED);
            $this->setResult(false);
        }

        if ($isVerified && $balance !== null && $balanceCap !== null && $type === Transaction::TRANSACTION_TYPE_INCREMENT && ($balance + $value) > $balanceCap) {
            $isVerified = false;

            $this->setError(ErrorCodes::ERROR_CODE_NEW_BALANCE_EXCEEDS_CAP);
            $this->setResult(false);
        }

        if ($isVerified && $balance !== null && $type === Transaction::TRANSACTION_TYPE_DECREMENT && $balance - $value < 0) {
            $isVerified = false;

            $this->setError(ErrorCodes::ERROR_CODE_BALANCE_INSUFFICIENT_OTHER);
            $this->setResult(false);
        }

        return $isVerified;
    }

    // Copied from MySQLRetrieveQuery

    public function getRetrieveQuery(): string
    {
        return "SELECT * FROM " . $this->getTable() . " WHERE username = ?";
    }

    public function getQuery(): string
    {
        $type = $this->getTransaction()->getType();
        $value = $this->getTransaction()->getValue();
        $balanceCap = $this->getTransaction()->getBalanceCap();

        // If the value is more than the balance cap, set it to the balance cap
        if ($balanceCap !== null && $value > $balanceCap) {
            $value = $balanceCap;
        }

        $statement = match ($type) {
            Transaction::TRANSACTION_TYPE_INCREMENT => $balanceCap !== null ? "IF (balance + " . $value . " <= " . $balanceCap . ", balance + " . $value . ", balance)" : "balance + " . $value,
            Transaction::TRANSACTION_TYPE_DECREMENT => "IF (balance - " . $value . " >= 0, balance - " . $value . ", balance)",
            Transaction::TRANSACTION_TYPE_SET => $value,
        };

        return "UPDATE " . $this->getTable() . " SET balance = " . $statement . " WHERE username = ?";
    }
}
