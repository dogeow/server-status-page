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
	resolved, err := r.walk(value)
	if err != nil {
		return nil, err
	}
	return json.Marshal(resolved)
}

func (r *Resolver) walk(value any) (any, error) {
	switch typed := value.(type) {
	case []any:
		out := make([]any, len(typed))
		for i, item := range typed {
			v, err := r.walk(item)
			if err != nil {
				return nil, err
			}
			out[i] = v
		}
		return out, nil
	case map[string]any:
		if ref, ok := typed["secretRef"].(string); ok && len(typed) == 1 {
			return r.Resolve(ref)
		}
		out := make(map[string]any, len(typed))
		for key, item := range typed {
			v, err := r.walk(item)
			if err != nil {
				return nil, err
			}
			out[key] = v
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
		value, ok = os.LookupEnv(strings.TrimPrefix(ref, "env://"))
		if !ok {
			return "", fmt.Errorf("secret environment variable is not set")
		}
	case strings.HasPrefix(ref, "env:"):
		value, ok = os.LookupEnv(strings.TrimPrefix(ref, "env:"))
		if !ok {
			return "", fmt.Errorf("secret environment variable is not set")
		}
	case strings.HasPrefix(ref, "file://") || strings.HasPrefix(ref, "file:"):
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
