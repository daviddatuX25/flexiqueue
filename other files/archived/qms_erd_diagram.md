erDiagram
    PROGRAM ||--o{ PROGRAM_PROCESS : "defines"
    PROGRAM ||--o{ FLOW_RULE : "has"
    PROGRAM ||--o{ STUB_PROGRAM : "receives stubs"
    PROGRAM ||--o{ DEVICE_ASSIGNMENT : "assigned devices"

    PROCESS_TYPE ||--o{ PROGRAM_PROCESS : "assigned"
    PROCESS_TYPE ||--o{ FLOW_RULE : "source_or_target"
    PROCESS_TYPE ||--o{ TABLE_PROCESS_TYPE : "handled_by"
    PROCESS_TYPE ||--o{ STUB_PROCESS_STATUS : "tracked_in"

    PROGRAM_PROCESS }o--|| PROCESS_TYPE : "references"
    PROGRAM_PROCESS }o--|| PROGRAM : "belongs_to"

    STAFF ||--o{ STAFF_DEVICE_ASSIGNMENT : "optionally_assigned_devices"
    STAFF_DEVICE_ASSIGNMENT }o--|| DEVICE : "uses"

    FLOW_RULE }o--|| PROGRAM : "belongs_to"
    FLOW_RULE }o--|| PROCESS_TYPE : "from_process"
    FLOW_RULE }o--|| PROCESS_TYPE : "to_process"

    TABLE_ENTITY ||--o{ TABLE_PROCESS_TYPE : "supports"
    TABLE_ENTITY ||--o{ STUB_PROCESS_STATUS : "processes"
    TABLE_ENTITY ||--o{ DEVICE_ASSIGNMENT : "assigned devices"

    TABLE_PROCESS_TYPE }o--|| PROCESS_TYPE : "references"
    TABLE_PROCESS_TYPE }o--|| TABLE_ENTITY : "references"

    STAFF ||--o{ STUB_PROGRAM : "registers"
    STAFF ||--o{ STUB_PROCESS_STATUS : "handles"
    STAFF ||--o{ DEVICE_SESSION : "logs_in"
    STAFF ||--o{ DEVICE_ASSIGNMENT : "assigns devices"

    STUB ||--o{ STUB_PROGRAM : "registered_to"

    STUB_PROGRAM ||--o{ STUB_PROCESS_STATUS : "tracks"

    DEVICE ||--o{ DEVICE_ASSIGNMENT : "assigned"
    DEVICE ||--o{ DEVICE_SESSION : "has sessions"

    DEVICE_ASSIGNMENT }o--|| DEVICE : "uses"
    DEVICE_ASSIGNMENT }o--|| PROGRAM : "scoped_to"
    DEVICE_ASSIGNMENT }o--|| TABLE_ENTITY : "assigned_to"
    DEVICE_ASSIGNMENT }o--|| STAFF : "assigned_by"

    DEVICE_SESSION }o--|| DEVICE : "uses"
    DEVICE_SESSION }o--|| STAFF : "logged_in_by"

    AUDIT_LOG }o--|| STUB : "logs"

    PROGRAM {
        int program_id PK
        string name
        string description
        boolean active
        date start_date
        date end_date
        datetime created_at
        datetime updated_at
    }

    PROCESS_TYPE {
        int process_type_id PK
        string name
        string description
        datetime created_at
    }

    PROGRAM_PROCESS {
        int program_process_id PK
        int program_id FK
        int process_type_id FK
        boolean required
        boolean is_initial "marks initial/start processes"
        datetime created_at
    }

    FLOW_RULE {
        int flow_rule_id PK
        int program_id FK
        int from_process_id FK
        int to_process_id FK
        string condition_type
        boolean can_override
        datetime created_at
    }

    TABLE_ENTITY {
        int table_id PK
        int program_id FK
        string table_name
        int max_client_seats
        boolean active
        datetime created_at
        datetime updated_at
    }

    TABLE_PROCESS_TYPE {
        int table_process_type_id PK
        int table_id FK
        int process_type_id FK
        datetime created_at
    }

    STAFF {
        int staff_id PK
        string username
        string password_hash
        string staff_name
        string role
        boolean active
        datetime created_at
    }

    STUB {
        string stub_id PK
        string alias_name
        string qr_code
        datetime printed_at
        datetime created_at
    }

    STUB_PROGRAM {
        int stub_program_id PK
        string stub_id FK
        int program_id FK
        int registered_by_staff_id FK
        string status
        datetime registered_at
        datetime expires_at
    }

    STUB_PROCESS_STATUS {
        int stub_process_status_id PK
        int stub_program_id FK
        int process_type_id FK
        string status
        int assigned_table_id FK
        int assigned_staff_id FK
        datetime started_at
        datetime completed_at
        boolean overridden
        string override_reason
        datetime created_at
    }

    DEVICE {
        int device_id PK
        string device_uuid UK "UUID"
        string device_type "QPD, QRD, QID, IOT_DISPLAY"
        string device_model
        string mac_address
        string ip_address
        string physical_location
        boolean active
        datetime last_seen
        datetime registered_at
        datetime created_at
    }

    DEVICE_ASSIGNMENT {
        int assignment_id PK
        int device_id FK
        int program_id FK
        int table_id FK "nullable"
        string assignment_role
        boolean active
        datetime assigned_at
        datetime unassigned_at
        int assigned_by_staff_id FK
    }

    DEVICE_SESSION {
        int session_id PK
        int device_id FK
        int staff_id FK
        datetime session_start
        datetime session_end
        string session_token UK
        datetime last_activity
    }

    STAFF_DEVICE_ASSIGNMENT {
        int staff_device_assignment_id PK
        int device_id FK
        int staff_id FK
        datetime assigned_from
        datetime assigned_until "nullable for open-ended assignment"
        string notes "context: table number, shift, location"
        datetime created_at
    }

    AUDIT_LOG {
        bigint log_id PK
        string stub_id FK
        int actor_id
        string actor_type
        string action
        string from_state
        string to_state
        string reason
        string metadata
        string ip_address
        datetime timestamp
    }
