### Contributing to Tina4

All contributors are welcome, especially if you are wanting to fix a bug or add functionality.

In order to contribute you need to fork this project and submit a pull request

- All code submitted will be subject to review
- Tina4 is highly opinionated from a design stand point so please discuss new features with the team.

#### Where can you make the most impact?

- Adding to documentation
- Submitting or fixing bugs
- Adding new database functionality (MSSQL,PostGres,CUBRID,ODBC are top priority)
- Assisting with localization (translating)

#### PHPDoc house style

Docblocks describe what the code does for the next reader. They are not a
changelog. Keep them accurate and current with the code.

Every public method and function carries a docblock with:

- A one-line summary of the behaviour, in the present tense ("Lists the tables
  in the connected database.").
- `@param <type> $name` for each parameter, describing what it is, not just its
  type.
- `@return <type>` describing what comes back (omit only for a `void`/`never`
  method where the summary already makes that clear).
- `@throws <Class>` for every exception the caller can reasonably hit, with the
  condition that triggers it ("@throws DatabaseException when the statement
  fails").

Rules:

- Describe the behaviour, never the fix. Write "Returns the parsed body" not
  "Fixed body parsing" or "Changed in 3.13.x". Version notes belong in the
  changelog and release notes, not in a docblock.
- No orphaned docblocks. A docblock must sit directly above the method,
  function, class, or property it documents. Delete a docblock when you delete
  its code, and update it in the same change when you change a signature.
- Types in `@param`/`@return` must match the real signature, including nullable
  (`?string`) and union (`int|string`) types. A docblock that disagrees with
  the signature is a bug.
- Keep it short. If the docblock is longer than the method, the method probably
  needs splitting, or the docblock is narrating instead of describing.

The AI-context generator (`Tina4\AITools::generateContext`) reflects this standard so
generated contribution guidance matches it. A lightweight lint for missing tags
may follow; until then this is reviewed by hand on every pull request.

Join the Slack channel by clicking on the link at https://tina4.com
