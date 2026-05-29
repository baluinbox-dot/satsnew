-- ============================================================
-- STAS - Smart Trailing Accumulation System
-- Database: u900311706_stas (create this in Hostinger first)
-- Run this file once via phpMyAdmin or Hostinger DB tool
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';

-- ============================================================
-- USERS
-- ============================================================
CREATE TABLE IF NOT EXISTS st_users (
  id          VARCHAR(60) PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  email       VARCHAR(150) NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,
  role        ENUM('superadmin','admin','user') DEFAULT 'user',
  phone       VARCHAR(20),
  isActive    TINYINT(1) DEFAULT 1,
  lastLogin   DATETIME,
  createdAt   DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- STRATEGY SETTINGS (one row per user; superadmin = global default)
-- ============================================================
CREATE TABLE IF NOT EXISTS st_settings (
  id              VARCHAR(60) PRIMARY KEY,
  ownerId         VARCHAR(60) NOT NULL,
  activationPct   DECIMAL(5,2) DEFAULT 10.00,
  pullbackPct     DECIMAL(5,2) DEFAULT 5.00,
  sellTargetPct   DECIMAL(5,2) DEFAULT 30.00,
  maxBuyCount     INT          DEFAULT 5,
  niftyFilterOn   TINYINT(1)   DEFAULT 0,
  dma200FilterOn  TINYINT(1)   DEFAULT 0,
  updatedAt       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (ownerId) REFERENCES st_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PORTFOLIO (one row per stock per user)
-- ============================================================
CREATE TABLE IF NOT EXISTS st_portfolio (
  id              VARCHAR(60) PRIMARY KEY,
  ownerId         VARCHAR(60) NOT NULL,
  symbol          VARCHAR(20)  NOT NULL,
  exchange        VARCHAR(10)  DEFAULT 'NSE',
  avgPrice        DECIMAL(10,2) DEFAULT 0.00,
  totalQty        INT           DEFAULT 0,
  totalInvestment DECIMAL(14,2) DEFAULT 0.00,
  buyCount        INT           DEFAULT 0,
  isActive        TINYINT(1)    DEFAULT 1,
  notes           TEXT,
  createdAt       DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ownerId) REFERENCES st_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TRANSACTIONS (every individual buy entry)
-- ============================================================
CREATE TABLE IF NOT EXISTS st_transactions (
  id            VARCHAR(60) PRIMARY KEY,
  ownerId       VARCHAR(60) NOT NULL,
  portfolioId   VARCHAR(60) NOT NULL,
  buyPrice      DECIMAL(10,2) NOT NULL,
  qty           INT           NOT NULL,
  buyNumber     INT           DEFAULT 1,
  txDate        DATE          NOT NULL,
  notes         TEXT,
  createdAt     DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ownerId)     REFERENCES st_users(id)     ON DELETE CASCADE,
  FOREIGN KEY (portfolioId) REFERENCES st_portfolio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- M1 TRACKER (one active cycle row per portfolio at any time)
-- Each new buy starts a fresh cycle row
-- ============================================================
CREATE TABLE IF NOT EXISTS st_m1tracker (
  id                VARCHAR(60) PRIMARY KEY,
  ownerId           VARCHAR(60) NOT NULL,
  portfolioId       VARCHAR(60) NOT NULL,
  cycleNumber       INT           DEFAULT 1,
  baseBuyPrice      DECIMAL(10,2) NOT NULL,   -- price of latest buy that started this cycle
  activationPrice   DECIMAL(10,2) NOT NULL,   -- baseBuyPrice + activationPct%
  triggerPrice      DECIMAL(10,2) NOT NULL,   -- activationPrice - pullbackPct%
  highestPrice      DECIMAL(10,2) DEFAULT 0,  -- highest CMP seen since cycle start (for M1 tracking)
  m1Activated       TINYINT(1)    DEFAULT 0,
  m1ActivatedAt     DATETIME,
  status            ENUM('waiting','activated','triggered','done','sold') DEFAULT 'waiting',
  createdAt         DATETIME DEFAULT CURRENT_TIMESTAMP,
  updatedAt         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (ownerId)     REFERENCES st_users(id)     ON DELETE CASCADE,
  FOREIGN KEY (portfolioId) REFERENCES st_portfolio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SIGNALS (buy / sell alerts log)
-- ============================================================
CREATE TABLE IF NOT EXISTS st_signals (
  id            VARCHAR(60) PRIMARY KEY,
  ownerId       VARCHAR(60) NOT NULL,
  portfolioId   VARCHAR(60) NOT NULL,
  signalType    ENUM('BUY','SELL') NOT NULL,
  signalPrice   DECIMAL(10,2),
  avgPrice      DECIMAL(10,2),
  profitPct     DECIMAL(6,2),
  message       TEXT,
  isRead        TINYINT(1) DEFAULT 0,
  isActioned    TINYINT(1) DEFAULT 0,
  signalDate    DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ownerId)     REFERENCES st_users(id)     ON DELETE CASCADE,
  FOREIGN KEY (portfolioId) REFERENCES st_portfolio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PRICE CACHE (avoid hammering Yahoo Finance API every refresh)
-- ============================================================
CREATE TABLE IF NOT EXISTS st_pricecache (
  id          VARCHAR(60) PRIMARY KEY,
  symbol      VARCHAR(20) NOT NULL UNIQUE,
  exchange    VARCHAR(10) DEFAULT 'NSE',
  lastPrice   DECIMAL(10,2),
  dayHigh     DECIMAL(10,2),
  dayLow      DECIMAL(10,2),
  volume      BIGINT,
  fetchedAt   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- INDEXES for faster queries
-- ============================================================
CREATE INDEX idx_portfolio_owner   ON st_portfolio(ownerId);
CREATE INDEX idx_transactions_port ON st_transactions(portfolioId);
CREATE INDEX idx_m1_portfolio      ON st_m1tracker(portfolioId, status);
CREATE INDEX idx_signals_owner     ON st_signals(ownerId, isRead);
CREATE INDEX idx_pricecache_sym    ON st_pricecache(symbol);
