package secret

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"sync"
)

type Resolver struct {
	fileRoot string
	mu       sync.RWMutex
	values   []string
}

func NewResolver(fileRoot string) *Resolver {
	if fileRoot != "" {
		fileRoot, _ = filepath.Abs(fileRoot)
	}
	return &Resolver{fileRoot: fileRoot}
}

func (r *Resolver) ResolveJSON(raw json.RawMessage) (json.RawMessage, error) {
	if len(raw) == 0 {
		return json.RawMessage(`{}`), nil
	}
	var value any
	if err := json.Unmarshal(raw, &value); err != nil {
		return nil, errors.New("probe config is not valid JSON")
	}
	resolved, err := r.walk(value, "")
	if err != nil {
		return nil, err
	}
	return json.Marshal(resolved)
}

func forbiddenSecretKey(key string) bool {
	switch strings.ToLower(key) {
	case "headers", "body", "query", "data", "payload", "form":
		return true
	default:
		return false
	}
}

func (r *Resolver) walk(value any, key string) (any, error) {
	switch typed := value.(type) {
	case []any:
		out := make([]any, len(typed))
		for i, item := range typed {
			v, err := r.walk(item, key)
			if err != nil {
				return nil, err
			}
			out[i] = v
		}
		return out, nil
	case map[string]any:
		if ref, ok := typed["secretRef"].(string); ok && len(typed) == 1 {
			if forbiddenSecretKey(key) {
				return nil, errors.New("secretRef is not allowed for this field")
			}
			return r.Resolve(ref)
		}
		out := make(map[string]any, len(typed))
		for childKey, item := range typed {
			nextKey := childKey
			if forbiddenSecretKey(key) {
				nextKey = key
			}
			v, err := r.walk(item, nextKey)
			if err != nil {
				return nil, err
			}
			out[childKey] = v
		}
		return out, nil
	default:
		return value, nil
	}
}

func (r *Resolver) Resolve(ref string) (string, error) {
	var value string
	var ok bool
	switch {
	case strings.HasPrefix(ref, "env://"):
		name := strings.TrimPrefix(ref, "env://")
		if !allowedEnvName(name) {
			return "", errors.New("secret environment variable is not allowed")
		}
		value, ok = os.LookupEnv(name)
		if !ok {
			return "", fmt.Errorf("secret environment variable is not set")
		}
	case strings.HasPrefix(ref, "env:"):
		name := strings.TrimPrefix(ref, "env:")
		if !allowedEnvName(name) {
			return "", errors.New("secret environment variable is not allowed")
		}
		value, ok = os.LookupEnv(name)
		if !ok {
			return "", fmt.Errorf("secret environment variable is not set")
		}
	case strings.HasPrefix(ref, "file://") || strings.HasPrefix(ref, "file:"):
		if r.fileRoot == "" {
			return "", errors.New("file secrets require secret_file_root")
		}
		path := strings.TrimPrefix(strings.TrimPrefix(ref, "file://"), "file:")
		abs, err := filepath.Abs(path)
		if err != nil {
			return "", errors.New("invalid secret file path")
		}
		if r.fileRoot != "" {
			root, rootErr := filepath.EvalSymlinks(r.fileRoot)
			resolved, pathErr := filepath.EvalSymlinks(abs)
			if rootErr != nil || pathErr != nil {
				return "", errors.New("cannot resolve secret file path")
			}
			rel, err := filepath.Rel(root, resolved)
			if err != nil || rel == ".." || strings.HasPrefix(rel, ".."+string(filepath.Separator)) {
				return "", errors.New("secret file is outside secret_file_root")
			}
			abs = resolved
		}
		file, err := os.Open(abs)
		if err != nil {
			return "", errors.New("cannot read secret file")
		}
		defer file.Close()
		info, err := file.Stat()
		if err != nil || !info.Mode().IsRegular() || info.Size() > 64*1024 {
			return "", errors.New("secret file must be a regular file no larger than 64 KiB")
		}
		b, err := io.ReadAll(io.LimitReader(file, 64*1024+1))
		if err != nil || len(b) > 64*1024 {
			return "", errors.New("cannot read secret file")
		}
		value = strings.TrimRight(string(b), "\r\n")
	default:
		return "", errors.New("secretRef must use env:// or file://")
	}
	if value == "" {
		return "", errors.New("resolved secret is empty")
	}
	r.mu.Lock()
	r.values = append(r.values, value)
	r.mu.Unlock()
	return value, nil
}

func allowedEnvName(name string) bool {
	if name == "" || strings.Contains(name, "=") || strings.ContainsRune(name, 0) {
		return false
	}
	return strings.HasPrefix(name, "STATUS_")
}

func (r *Resolver) Redact(message string) string {
	r.mu.RLock()
	defer r.mu.RUnlock()
	for _, value := range r.values {
		if len(value) >= 4 {
			message = strings.ReplaceAll(message, value, "[REDACTED]")
		}
	}
	return message
}

func (r *Resolver) Remember(value string) {
	if value == "" {
		return
	}
	r.mu.Lock()
	r.values = append(r.values, value)
	r.mu.Unlock()
}
