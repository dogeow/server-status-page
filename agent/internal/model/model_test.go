package model

import (
	"encoding/json"
	"testing"
)

func TestPlanAcceptsNumericControlPlaneIdentifiers(t *testing.T) {
	var plan Plan
	err := json.Unmarshal([]byte(`{"version":3,"monitors":[{"id":42,"type":"http","enabled":true,"interval_seconds":60,"timeout_ms":5000,"config_version":"7","config":{"url":"https://example.test"}}]}`), &plan)
	if err != nil {
		t.Fatal(err)
	}
	if plan.Version != "3" || len(plan.Monitors) != 1 || plan.Monitors[0].ID != "42" || plan.Monitors[0].ConfigVersion != "7" {
		t.Fatalf("unexpected plan: %#v", plan)
	}
}

func TestPlanAcceptsStringIdentifiers(t *testing.T) {
	var plan Plan
	err := json.Unmarshal([]byte(`{"version":"v3","monitors":[{"id":"monitor-42","type":"tcp","enabled":true,"config_version":7,"config":{"address":"localhost:80"}}]}`), &plan)
	if err != nil {
		t.Fatal(err)
	}
	if plan.Version != "v3" || plan.Monitors[0].ID != "monitor-42" || plan.Monitors[0].ConfigVersion != "7" {
		t.Fatalf("unexpected plan: %#v", plan)
	}
}
