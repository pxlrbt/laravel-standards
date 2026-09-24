## pxlrbt Conventions

- Start Eloquent queries with `Model::query()`.
- Structure tests with Arrange – Act – Assert.
- Every new test file must declare the class it tests with `mutates(Subject::class);` at the top (after `uses()`). CI mutation-tests new and changed test files against those classes and fails below the minimum score, so assert on behavior and edge cases, not just that code runs.
- Run `composer format` (Rector + Pint) and `composer analyse` (PHPStan) before finishing a change. Do not add errors to `phpstan-baseline.neon`; fix them.
- Default users and other required production data live in invokable classes in `database/states` (pxlrbt/laravel-database-state). They run automatically after `migrate` and must be idempotent.

### Pest Assertions

- When asserting multiple things about the same subject, use higher-order expectations: call the subject's methods/properties directly on the expectation chain. Do not repeat `expect()` or bridge with `->and()`.

@verbatim
<code-snippet name="Higher-order expectations" lang="php">
// Good
expect($user)
    ->isAdmin()->toBeTrue()
    ->canAccess($panel)->toBeTrue();

// Avoid
expect($user->isAdmin())->toBeTrue()
    ->and($user->canAccess($panel))->toBeTrue();
</code-snippet>
@endverbatim

- This also applies to a single assertion: prefer `expect($user)->isAdmin()->toBeFalse();` over `expect($user->isAdmin())->toBeFalse();`.
- It only applies when the subject is the object passed to `expect()`, not when the expected value is a free function call.
