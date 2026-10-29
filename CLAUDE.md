# Claude Code adapter for Moyasar-payment--STG

@AGENTS.md

Claude-specific notes only; everything else is in `AGENTS.md` and the shared knowledge base.

- Claude's auto-memory is machine-local. The shared knowledge base (`../../claude/`) is the source of
  truth; update it, not only memory, after meaningful work.
- When launched above this folder, the `wp-content/CLAUDE.md` entry point also loads; it imports the same
  working rules, so the two do not conflict.
- If the ECC GateGuard hook refuses a first command or edit, state the facts it asks for and retry. Do
  not disable it; that is the owner's decision.
