# Entity-Relationship Diagram

## Scope

The MySQL 8 schema behind the ticket-management app: users and roles, tickets,
master data (categories, priorities, statuses, status transitions), requesters,
and the audit trail. See `docs/ticket-lifecycle.md` for the workflow this
schema encodes.

## Diagram

```mermaid
erDiagram
    users {
        bigint id PK
        string name
        string email UK
        string password
        enum role "admin | agent"
        boolean is_active "default true"
        timestamp created_at
        timestamp updated_at
    }
    categories {
        bigint id PK
        string name UK
        string slug UK
        text description "nullable"
        char color "#RRGGBB"
        boolean is_active "default true"
        smallint sort_order "default 0"
        timestamp deleted_at "soft delete"
    }
    priorities {
        bigint id PK
        string name UK
        string slug UK
        tinyint level UK "1 = lowest"
        char color "#RRGGBB"
        boolean is_default "exactly one row"
    }
    statuses {
        bigint id PK
        string name UK
        string slug UK
        enum bucket "open | pending | done"
        char color "#RRGGBB"
        boolean is_default "exactly one row"
        boolean is_terminal
        smallint sort_order
    }
    status_transitions {
        bigint id PK
        bigint from_status_id FK
        bigint to_status_id FK
        enum required_role "admin | agent | null"
        timestamp created_at
        timestamp updated_at
    }
    requesters { bigint id PK string name string email UK string phone string company timestamp created_at timestamp updated_at }
    tickets { bigint id PK char reference UK string subject text description bigint requester_id FK bigint category_id FK bigint priority_id FK bigint status_id FK bigint assigned_to FK bigint created_by FK tinyint escalation_level timestamp escalated_at bigint escalated_by FK text escalation_reason timestamp first_responded_at timestamp resolved_at timestamp closed_at timestamp created_at timestamp updated_at timestamp deleted_at }
    ticket_sequences { smallint year PK int next_number }
    ticket_activities {
        bigint id PK
        bigint ticket_id FK
        bigint user_id FK "nullable -- null means the system acted"
        string event
        string field "nullable"
        string old_value "nullable"
        string new_value "nullable"
        json meta
        timestamp created_at "no updated_at -- append-only"
    }
    ticket_assignment_requests {
        bigint id PK
        bigint ticket_id FK "cascadeOnDelete -- a request is transient, not an audit record"
        bigint user_id FK "the requesting agent, cascadeOnDelete"
        enum status "pending | approved | declined"
        string note "nullable"
        bigint decided_by FK "nullable, nullOnDelete"
        timestamp decided_at "nullable"
        string decision_note "nullable"
        timestamp created_at
        timestamp updated_at
    }
    requesters ||--o{ tickets : requester_id
    categories ||--o{ tickets : category_id
    priorities ||--o{ tickets : priority_id
    statuses ||--o{ tickets : status_id
    statuses ||--o{ status_transitions : from_status_id
    statuses ||--o{ status_transitions : to_status_id
    users ||--o{ tickets : assigned_to
    users ||--o{ tickets : created_by
    users ||--o{ tickets : escalated_by
    tickets ||--o{ ticket_activities : ticket_id
    tickets ||--o{ ticket_assignment_requests : ticket_id
    users ||--o{ ticket_assignment_requests : user_id
```

## Table notes

| Table | Purpose | Owning story |
|-------|---------|--------------|
| `users` | Admin and agent logins; requesters are contact records. Role defaults to `agent` for least privilege. | TM-8 |
| `categories` | Ticket classification; admin-managed and soft-deleted. | TM-16 |
| `priorities` | Low/Medium/High/Urgent; `level` is escalation order. | TM-16 |
| `statuses` | Configurable workflow states. | TM-16 |
| `status_transitions` | The ticket lifecycle as data: one row per legal move, optionally role-gated. Seeded with 14 edges; editable at runtime. | TM-37 |
| `requesters` | Contact records for people tickets are about; no login capability. | TM-21 |
| `tickets` | Core soft-deleted record; foreign keys restrict except user references set null. | TM-21 |
| `ticket_sequences` | One row per year backing ticket references. | TM-21 |
| `ticket_activities` | Ticket audit trail: one row per event (`ticket_id`, `user_id` nullable, `event`, `field`, `old_value`, `new_value`, `meta` json, `created_at` only -- no `updated_at`). Never modified is enforced, not assumed: the model uses an append-only Eloquent builder that refuses every write but `insert` (TM-48), and `Ticket::forceDelete()` is refused because `ticket_id` is `ON DELETE CASCADE`. Changing that foreign key to `RESTRICT` is the complete fix and is recommended as its own story. | TM-45, TM-48 |
| `ticket_assignment_requests` | An agent's ask to be assigned an unassigned ticket, reviewed by an admin. Both foreign keys cascade on delete -- unlike every other table here, a request is a transient intention, not a durable audit record; the durable record is the `assignment_requested`/`assignment_request_declined` activity rows, which survive because `ticket_activities.user_id` is `nullOnDelete`. | — |

FULLTEXT index updates at commit; invisible inside open transaction. `innodb_ft_min_token_size = 3`.

Priority and status defaults use a unique index over a generated value. Flagged rows generate `1`; all other rows generate `NULL`, allowing many non-default rows but never two defaults.

`status_transitions` is unique on `(from_status_id, to_status_id)`; both foreign keys RESTRICT, so a status must lose its edges before it can be deleted.
