CREATE TABLE IF NOT EXISTS saas_tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    slug VARCHAR(100) NOT NULL UNIQUE,
    company_name VARCHAR(190) NOT NULL,
    legal_name VARCHAR(255) NULL,
    database_name VARCHAR(100) NOT NULL UNIQUE,
    status ENUM('pending','active','suspended','archived')
        NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS saas_platform_branding (
    id TINYINT UNSIGNED PRIMARY KEY,
    platform_name VARCHAR(190) NOT NULL,
    logo_path VARCHAR(500) NULL,
    icon_path VARCHAR(500) NULL,
    primary_color CHAR(7) NOT NULL DEFAULT '#050505',
    accent_color CHAR(7) NOT NULL DEFAULT '#ffcc20',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS saas_tenant_branding (
    tenant_id BIGINT UNSIGNED PRIMARY KEY,
    company_name VARCHAR(190) NOT NULL,
    legal_name VARCHAR(255) NULL,
    capital VARCHAR(190) NULL,
    logo_path VARCHAR(500) NULL,
    icon_path VARCHAR(500) NULL,
    address VARCHAR(500) NULL,
    phone VARCHAR(100) NULL,
    email VARCHAR(190) NULL,
    rccm VARCHAR(190) NULL,
    tax_number VARCHAR(190) NULL,
    primary_color CHAR(7) NOT NULL DEFAULT '#050505',
    accent_color CHAR(7) NOT NULL DEFAULT '#ffcc20',
    surface_color CHAR(7) NOT NULL DEFAULT '#ffffff',
    text_color CHAR(7) NOT NULL DEFAULT '#0f172a',
    muted_color CHAR(7) NOT NULL DEFAULT '#64748b',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_branding_tenant
        FOREIGN KEY (tenant_id) REFERENCES saas_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS saas_modules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS saas_tenant_modules (
    tenant_id BIGINT UNSIGNED NOT NULL,
    module_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (tenant_id, module_id),
    FOREIGN KEY (tenant_id) REFERENCES saas_tenants(id),
    FOREIGN KEY (module_id) REFERENCES saas_modules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS saas_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(190) NOT NULL,
    price_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'XOF',
    billing_period ENUM('monthly','quarterly','yearly','custom')
        NOT NULL DEFAULT 'monthly',
    active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS saas_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    status ENUM('trial','active','past_due','suspended','cancelled')
        NOT NULL DEFAULT 'trial',
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES saas_tenants(id),
    FOREIGN KEY (plan_id) REFERENCES saas_plans(id),
    INDEX idx_subscription_tenant_status (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
