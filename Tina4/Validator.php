<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Request body validator with chainable rules.
 *
 * Usage:
 *   $validator = new Validator($request->body ?? []);
 *   $validator->required("name", "email")
 *             ->email("email")
 *             ->minLength("name", 2)
 *             ->maxLength("name", 100)
 *             ->integer("age")
 *             ->min("age", 0)
 *             ->max("age", 150)
 *             ->inList("role", ["admin", "user", "guest"])
 *             ->regex("phone", '/^\+?[\d\s\-]+$/');
 *
 *   if (!$validator->isValid()) {
 *       return $response->sendError("VALIDATION_FAILED", $validator->errors()[0]['message'], 400);
 *   }
 */
class Validator
{
    /** @var array<string, mixed> The data to validate */
    private array $data;

    /** @var array<int, array{field: string, message: string}> Collected errors */
    private array $validationErrors = [];

    /**
     * @param array<string, mixed>|object $data Request body data
     */
    public function __construct(mixed $data = [])
    {
        if (is_object($data)) {
            $data = (array)$data;
        }
        $this->data = is_array($data) ? $data : [];
    }

    /**
     * Check that one or more fields are present and non-empty.
     *
     * @param string ...$fields
     * @return $this
     */
    public function required(string ...$fields): self
    {
        foreach ($fields as $field) {
            $value = $this->data[$field] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $this->validationErrors[] = [
                    'field' => $field,
                    'message' => "{$field} is required",
                ];
            }
        }
        return $this;
    }

    /**
     * Check that a field contains a valid email address.
     *
     * @return $this
     */
    public function email(string $field): self
    {
        $value = $this->data[$field] ?? null;
        if ($value === null) {
            return $this;
        }
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->validationErrors[] = [
                'field' => $field,
                'message' => "{$field} must be a valid email address",
            ];
        }
        return $this;
    }

    /**
     * Check that a string field has at least $length characters.
     *
     * @return $this
     */
    public function minLength(string $field, int $length): self
    {
        $value = $this->data[$field] ?? null;
        if ($value === null) {
            return $this;
        }
        if (!is_string($value) || mb_strlen($value) < $length) {
            $this->validationErrors[] = [
                'field' => $field,
                'message' => "{$field} must be at least {$length} characters",
            ];
        }
        return $this;
    }

    /**
     * Check that a string field has at most $length characters.
     *
     * @return $this
     */
    public function maxLength(string $field, int $length): self
    {
        $value = $this->data[$field] ?? null;
        if ($value === null) {
            return $this;
        }
        if (!is_string($value) || mb_strlen($value) > $length) {
            $this->validationErrors[] = [
                'field' => $field,
                'message' => "{$field} must be at most {$length} characters",
            ];
        }
        return $this;
    }

    /**
     * Check that a field is an integer (or can be parsed as one).
     *
     * @return $this
     */
    public function integer(string $field): self
    {
        $value = $this->data[$field] ?? null;
        if ($value === null) {
            return $this;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false && !is_int($value)) {
            $this->validationErrors[] = [
                'field' => $field,
                'message' => "{$field} must be an integer",
            ];
        }
        return $this;
    }

    /**
     * Check that a numeric field is >= $minimum.
     *
     * @return $this
     */
    public function min(string $field, int|float $minimum): self
    {
        $value = $this->data[$field] ?? null;
        if ($value === null) {
            return $this;
        }
        if (!is_numeric($value)) {
            return $this;
        }
        if ((float)$value < $minimum) {
            $this->validationErrors[] = [
                'field' => $field,
                'message' => "{$field} must be at least {$minimum}",
            ];
        }
        return $this;
    }

    /**
     * Check that a numeric field is <= $maximum.
     *
     * @return $this
     */
    public function max(string $field, int|float $maximum): self
    {
        $value = $this->data[$field] ?? null;
        if ($value === null) {
            return $this;
        }
        if (!is_numeric($value)) {
            return $this;
        }
        if ((float)$value > $maximum) {
            $this->validationErrors[] = [
                'field' => $field,
                'message' => "{$field} must be at most {$maximum}",
            ];
        }
        return $this;
    }

    /**
     * Check that a field's value is one of the allowed values.
     *
     * @param array<mixed> $allowed
     * @return $this
     */
    public function inList(string $field, array $allowed): self
    {
        $value = $this->data[$field] ?? null;
        if ($value === null) {
            return $this;
        }
        if (!in_array($value, $allowed, true)) {
            $list = json_encode($allowed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->validationErrors[] = [
                'field' => $field,
                'message' => "{$field} must be one of {$list}",
            ];
        }
        return $this;
    }

    /**
     * Check that a field matches a regular expression.
     *
     * @return $this
     */
    public function regex(string $field, string $pattern): self
    {
        $value = $this->data[$field] ?? null;
        if ($value === null) {
            return $this;
        }
        if (!is_string($value) || !preg_match($pattern, $value)) {
            $this->validationErrors[] = [
                'field' => $field,
                'message' => "{$field} does not match the required format",
            ];
        }
        return $this;
    }

    /**
     * Return the list of validation errors (empty if valid).
     *
     * @return array<int, array{field: string, message: string}>
     */
    public function errors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Return true if no validation errors have been recorded.
     */
    public function isValid(): bool
    {
        return count($this->validationErrors) === 0;
    }
}
