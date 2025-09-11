# Source ID Fallbacks

Some providers omit numeric identifiers for categories, series, seasons or episodes. Child playlist sync needs a deterministic key for upserts and deletes, so the application generates prefixed fallback values like `season-9904`.

Database columns and model casts for `source_*_id` fields are now strings so these synthetic identifiers can be stored alongside real numeric IDs without type errors.

Storing both forms prevents collisions when a real ID is later provided and avoids SQL errors such as `invalid input syntax for type integer` during child playlist synchronization.
