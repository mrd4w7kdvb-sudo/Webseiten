package server

import (
	"testing"
	"time"
)

// Testvektor aus RFC 6238 (SHA1, Schlüssel "12345678901234567890").
func TestTOTPRFCVector(t *testing.T) {
	secret := b32.EncodeToString([]byte("12345678901234567890"))
	code, err := totpCode(secret, 59/totpStep)
	if err != nil || code != "287082" {
		t.Fatalf("Code %q, Fehler %v – erwartet 287082", code, err)
	}
	code, _ = totpCode(secret, 1111111109/totpStep)
	if code != "081804" {
		t.Fatalf("Code %q – erwartet 081804", code)
	}
}

func TestTOTPNoReplay(t *testing.T) {
	secret := newTOTPSecret()
	now := time.Unix(1_800_000_000, 0)
	code, _ := totpCode(secret, now.Unix()/totpStep)
	step, ok := totpVerify(secret, code, 0, now)
	if !ok {
		t.Fatal("gültiger Code abgelehnt")
	}
	if _, ok := totpVerify(secret, code, step, now); ok {
		t.Fatal("bereits verwendeter Code darf nicht erneut funktionieren")
	}
	if _, ok := totpVerify(secret, "000000", 0, now.Add(time.Hour)); ok && code == "000000" {
		t.Fatal("unerwartet")
	}
}
