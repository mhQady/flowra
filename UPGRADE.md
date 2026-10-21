# Upgrade Guide

## From 0.3.x to 0.4.0

### Status views send details only on opt-in

Status views no longer send `applied_by`, `comment`, `transition`, `from` or `relations` on the real
status unless the view calls `withDetails()`.

Before, a status that no rule remapped carried all of them, even on a view that declared nothing,
such as `StatusView::make('external')->labels('...')`. Now a view without the opt-in returns
`appliedBy` null, `comment` `[]`, `transition` and `from` null, and `relations` `[]`.

**Unchanged:**
- The built-in `default` view still sends details, so `statusView()->get()` with no name behaves as
  before.
- A rule's `keepDetails()` still keeps details on the status it remaps. The view-level opt-in does
  not reach remapped statuses.
- `remapped`, `isRemapped()`, `recordedState` and `sub_state` are unchanged.

**To keep the old output**, opt each view in:

```php
StatusView::make('back_office')->withDetails();
```

```php
// config/flowra.php
'status_views' => [
    'views' => [
        'back_office' => ['keep_details' => true],
    ],
],
```

The method is `withDetails()`, not `keepDetails()`. `StatusView::keepDetails()` still applies to the
rule added last.

**`with()` now requires an opt-in.** A view or read that declares `with(...)` while nothing opts in
to details throws a `LogicException` from `get()`. "Nothing" means no `withDetails()` on the view and
no rule with `keepDetails()`. Add `withDetails()`, or drop the relations.

**A view that replaces `default`** in config or in `statusViews()` is a view like any other. Add
`withDetails()` / `'keep_details' => true` if it should keep sending details.
