//go:build !linux && !windows && !darwin

package agent

func battery() (batteryState, bool)               { return batteryState{}, false }
func cpuTemperature() (float64, bool)             { return 0, false }
func serviceRunning(string) (bool, string)        { return false, "nicht unterstützt" }
func systemLogs(*state, int) ([]rawLog, error)    { return nil, nil }
func localIdentities() (accounts, names []string) { return nil, nil }
