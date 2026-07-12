package probe

import (
	"context"
	"crypto/tls"
	"database/sql"
	"encoding/json"
	"errors"
	"net"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/go-sql-driver/mysql"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/stdlib"
	"github.com/redis/go-redis/v9"
	"github.com/statusforge/status-agent/internal/model"
)

type mysqlConfig struct {
	DSN      string `json:"dsn,omitempty"`
	Host     string `json:"host"`
	Port     int    `json:"port,omitempty"`
	User     string `json:"user"`
	Password string `json:"password,omitempty"`
	Database string `json:"database,omitempty"`
	TLSMode  string `json:"tls_mode,omitempty"`
}

func validateMySQL(raw json.RawMessage) error {
	var cfg mysqlConfig
	if err := decodeConfig(raw, &cfg); err != nil {
		return err
	}
	if cfg.DSN != "" {
		if _, err := mysql.ParseDSN(cfg.DSN); err != nil {
			return errors.New("mysql dsn is invalid")
		}
		return nil
	}
	if strings.TrimSpace(cfg.Host) == "" {
		return errors.New("mysql host is required")
	}
	if cfg.Port == 0 {
		cfg.Port = 3306
	}
	if cfg.Port < 1 || cfg.Port > 65535 {
		return errors.New("mysql port is invalid")
	}
	if cfg.User == "" {
		return errors.New("mysql user is required")
	}
	if !validTLSMode(cfg.TLSMode) {
		return errors.New("mysql tls_mode must be required, preferred, insecure, or disabled")
	}
	return nil
}

func runMySQL(ctx context.Context, raw json.RawMessage, connectTimeout time.Duration) Outcome {
	var cfg mysqlConfig
	_ = decodeConfig(raw, &cfg)
	operationTimeout := remainingTimeout(ctx, connectTimeout)
	var driverCfg *mysql.Config
	if cfg.DSN != "" {
		driverCfg, _ = mysql.ParseDSN(cfg.DSN)
		driverCfg.Timeout, driverCfg.ReadTimeout, driverCfg.WriteTimeout = connectTimeout, operationTimeout, operationTimeout
	} else {
		if cfg.Port == 0 {
			cfg.Port = 3306
		}
		tlsMode := "true"
		switch cfg.TLSMode {
		case "disabled":
			tlsMode = ""
		case "preferred":
			tlsMode = "preferred"
		case "insecure":
			tlsMode = "skip-verify"
		}
		driverCfg = &mysql.Config{User: cfg.User, Passwd: cfg.Password, Net: "tcp", Addr: net.JoinHostPort(cfg.Host, strconv.Itoa(cfg.Port)), DBName: cfg.Database, Timeout: connectTimeout, ReadTimeout: operationTimeout, WriteTimeout: operationTimeout, ParseTime: true, TLSConfig: tlsMode}
	}
	db, err := sql.Open("mysql", driverCfg.FormatDSN())
	if err != nil {
		return Outcome{Status: model.StatusConfigError, ErrorCode: "mysql_config_invalid", Message: "invalid MySQL configuration"}
	}
	defer db.Close()
	db.SetMaxOpenConns(1)
	db.SetMaxIdleConns(1)
	connectStarted := time.Now()
	conn, err := db.Conn(ctx)
	if err != nil {
		return fromError(ctx, "mysql_connect_failed", err)
	}
	defer conn.Close()
	if err := conn.PingContext(ctx); err != nil {
		return fromError(ctx, "mysql_connect_failed", err)
	}
	connectMS := time.Since(connectStarted).Milliseconds()
	queryStarted := time.Now()
	var one int
	if err := conn.QueryRowContext(ctx, "SELECT 1").Scan(&one); err != nil {
		return fromError(ctx, "mysql_query_failed", err)
	}
	if one != 1 {
		return Outcome{Status: model.StatusFailed, ErrorCode: "mysql_query_mismatch", Message: "MySQL SELECT 1 returned an unexpected value"}
	}
	return Outcome{Status: model.StatusOK, Metrics: map[string]any{"connect_ms": connectMS, "query_ms": time.Since(queryStarted).Milliseconds()}}
}

type postgresConfig struct {
	DSN      string `json:"dsn,omitempty"`
	Host     string `json:"host"`
	Port     int    `json:"port,omitempty"`
	User     string `json:"user"`
	Password string `json:"password,omitempty"`
	Database string `json:"database,omitempty"`
	TLSMode  string `json:"tls_mode,omitempty"`
}

func validatePostgres(raw json.RawMessage) error {
	var cfg postgresConfig
	if err := decodeConfig(raw, &cfg); err != nil {
		return err
	}
	if cfg.DSN != "" {
		if _, err := pgx.ParseConfig(cfg.DSN); err != nil {
			return errors.New("postgres dsn is invalid")
		}
		return nil
	}
	if strings.TrimSpace(cfg.Host) == "" {
		return errors.New("postgres host is required")
	}
	if cfg.Port == 0 {
		cfg.Port = 5432
	}
	if cfg.Port < 1 || cfg.Port > 65535 {
		return errors.New("postgres port is invalid")
	}
	if cfg.User == "" {
		return errors.New("postgres user is required")
	}
	if !validTLSMode(cfg.TLSMode) {
		return errors.New("postgres tls_mode must be required, preferred, insecure, or disabled")
	}
	return nil
}

func runPostgres(ctx context.Context, raw json.RawMessage, connectTimeout time.Duration) Outcome {
	var cfg postgresConfig
	_ = decodeConfig(raw, &cfg)
	var connectionString string
	if cfg.DSN != "" {
		connectionString = cfg.DSN
	} else {
		if cfg.Port == 0 {
			cfg.Port = 5432
		}
		sslMode := "verify-full"
		switch cfg.TLSMode {
		case "disabled":
			sslMode = "disable"
		case "preferred":
			sslMode = "prefer"
		case "insecure":
			sslMode = "require"
		}
		u := &url.URL{Scheme: "postgres", Host: net.JoinHostPort(cfg.Host, strconv.Itoa(cfg.Port)), Path: "/" + cfg.Database, User: url.UserPassword(cfg.User, cfg.Password)}
		q := u.Query()
		q.Set("sslmode", sslMode)
		u.RawQuery = q.Encode()
		connectionString = u.String()
	}
	parsed, err := pgx.ParseConfig(connectionString)
	if err != nil {
		return Outcome{Status: model.StatusConfigError, ErrorCode: "postgres_config_invalid", Message: "invalid PostgreSQL configuration"}
	}
	if cfg.TLSMode == "insecure" && parsed.TLSConfig != nil {
		parsed.TLSConfig.InsecureSkipVerify = true
	} // #nosec G402: explicit monitor option.
	parsed.ConnectTimeout = connectTimeout
	db := stdlib.OpenDB(*parsed)
	defer db.Close()
	db.SetMaxOpenConns(1)
	db.SetMaxIdleConns(1)
	connectStarted := time.Now()
	conn, err := db.Conn(ctx)
	if err != nil {
		return fromError(ctx, "postgres_connect_failed", err)
	}
	defer conn.Close()
	if err := conn.PingContext(ctx); err != nil {
		return fromError(ctx, "postgres_connect_failed", err)
	}
	connectMS := time.Since(connectStarted).Milliseconds()
	queryStarted := time.Now()
	var one int
	if err := conn.QueryRowContext(ctx, "SELECT 1").Scan(&one); err != nil {
		return fromError(ctx, "postgres_query_failed", err)
	}
	if one != 1 {
		return Outcome{Status: model.StatusFailed, ErrorCode: "postgres_query_mismatch", Message: "PostgreSQL SELECT 1 returned an unexpected value"}
	}
	return Outcome{Status: model.StatusOK, Metrics: map[string]any{"connect_ms": connectMS, "query_ms": time.Since(queryStarted).Milliseconds()}}
}

type redisConfig struct {
	URL             string `json:"url,omitempty"`
	Address         string `json:"address"`
	Host            string `json:"host,omitempty"`
	Port            int    `json:"port,omitempty"`
	Mode            string `json:"mode,omitempty"`
	Username        string `json:"username,omitempty"`
	Password        string `json:"password,omitempty"`
	Database        int    `json:"database,omitempty"`
	TLS             bool   `json:"tls,omitempty"`
	InsecureTLS     bool   `json:"insecure_tls,omitempty"`
	CapabilityWrite bool   `json:"capability_write,omitempty"`
	KeyPrefix       string `json:"key_prefix,omitempty"`
}

func validateRedis(raw json.RawMessage) error {
	var cfg redisConfig
	if err := decodeConfig(raw, &cfg); err != nil {
		return err
	}
	if cfg.URL != "" {
		if _, err := redis.ParseURL(cfg.URL); err != nil {
			return errors.New("redis url is invalid")
		}
	} else {
		if cfg.Address == "" && strings.TrimSpace(cfg.Host) == "" {
			return errors.New("redis address or host is required")
		}
		if cfg.Port < 0 || cfg.Port > 65535 {
			return errors.New("redis port is invalid")
		}
		address := redisAddress(cfg)
		if _, _, err := net.SplitHostPort(address); err != nil {
			return errors.New("redis address must be host:port")
		}
	}
	if cfg.Database < 0 {
		return errors.New("redis database cannot be negative")
	}
	if len(cfg.KeyPrefix) > 128 {
		return errors.New("redis key_prefix is too long")
	}
	if cfg.Mode != "" && cfg.Mode != "ping" && cfg.Mode != "capability" && cfg.Mode != "read_write" {
		return errors.New("redis mode must be ping, capability, or read_write")
	}
	return nil
}

func runRedis(ctx context.Context, raw json.RawMessage, connectTimeout time.Duration) Outcome {
	var cfg redisConfig
	_ = decodeConfig(raw, &cfg)
	operationTimeout := remainingTimeout(ctx, connectTimeout)
	var options *redis.Options
	if cfg.URL != "" {
		options, _ = redis.ParseURL(cfg.URL)
		options.DialTimeout, options.ReadTimeout, options.WriteTimeout = connectTimeout, operationTimeout, operationTimeout
		options.MaxRetries, options.PoolSize = 0, 1
	} else {
		options = &redis.Options{Addr: redisAddress(cfg), Username: cfg.Username, Password: cfg.Password, DB: cfg.Database, DialTimeout: connectTimeout, ReadTimeout: operationTimeout, WriteTimeout: operationTimeout, MaxRetries: 0, PoolSize: 1}
	}
	if cfg.TLS && options.TLSConfig == nil {
		options.TLSConfig = &tls.Config{MinVersion: tls.VersionTLS12, InsecureSkipVerify: cfg.InsecureTLS}
	} // #nosec G402: explicit monitor option.
	if cfg.InsecureTLS && options.TLSConfig != nil {
		options.TLSConfig.InsecureSkipVerify = true
	}
	client := redis.NewClient(options)
	defer client.Close()
	if pong, err := client.Ping(ctx).Result(); err != nil {
		return fromError(ctx, "redis_ping_failed", err)
	} else if pong != "PONG" {
		return Outcome{Status: model.StatusFailed, ErrorCode: "redis_ping_mismatch", Message: "Redis did not return PONG"}
	}
	capabilityWrite := cfg.CapabilityWrite || cfg.Mode == "capability" || cfg.Mode == "read_write"
	metrics := map[string]any{"write_capability": capabilityWrite}
	if capabilityWrite {
		prefix := cfg.KeyPrefix
		if prefix == "" {
			prefix = "status-agent:"
		}
		key, value := prefix+randomHex(12), randomHex(16)
		defer func() {
			cleanupCtx, cancel := context.WithTimeout(context.Background(), connectTimeout)
			defer cancel()
			_ = client.Del(cleanupCtx, key).Err()
		}()
		if err := client.Set(ctx, key, value, 30*time.Second).Err(); err != nil {
			return fromError(ctx, "redis_write_failed", err)
		}
		got, err := client.Get(ctx, key).Result()
		if err != nil {
			return fromError(ctx, "redis_read_failed", err)
		}
		if got != value {
			return Outcome{Status: model.StatusFailed, ErrorCode: "redis_value_mismatch", Message: "Redis SET/GET value mismatch"}
		}
		if err := client.Del(ctx, key).Err(); err != nil {
			return fromError(ctx, "redis_delete_failed", err)
		}
	}
	return Outcome{Status: model.StatusOK, Metrics: metrics}
}

func validTLSMode(mode string) bool {
	switch mode {
	case "", "required", "preferred", "insecure", "disabled":
		return true
	}
	return false
}
func redisAddress(cfg redisConfig) string {
	if cfg.Address != "" {
		return cfg.Address
	}
	port := cfg.Port
	if port == 0 {
		port = 6379
	}
	return net.JoinHostPort(cfg.Host, strconv.Itoa(port))
}

func remainingTimeout(ctx context.Context, fallback time.Duration) time.Duration {
	if deadline, ok := ctx.Deadline(); ok {
		if remaining := time.Until(deadline); remaining > 0 {
			return remaining
		}
	}
	return fallback
}
