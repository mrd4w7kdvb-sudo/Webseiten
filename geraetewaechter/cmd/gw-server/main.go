// gw-server ist der zentrale Monitoring-Server des Gerätewächters.
//
//	gw-server                       Server starten (Konfiguration über GW_* Umgebungsvariablen)
//	gw-server passwd <benutzer>     Admin anlegen bzw. Passwort setzen (liest Passwort von stdin)
package main

import (
	"bufio"
	"context"
	"fmt"
	"log"
	"os"
	"os/signal"
	"strings"
	"syscall"

	"geraetewaechter/internal/server"
)

func main() {
	log.SetFlags(log.LstdFlags)
	cfg := server.ConfigFromEnv()
	if len(os.Args) > 1 {
		switch os.Args[1] {
		case "passwd":
			if len(os.Args) < 3 {
				fmt.Fprintln(os.Stderr, "Aufruf: gw-server passwd <benutzer>")
				os.Exit(2)
			}
			cfg.SkipBootstrap = true
			s, err := server.New(cfg)
			if err != nil {
				log.Fatal(err)
			}
			fmt.Fprint(os.Stderr, "Neues Passwort (mind. 10 Zeichen): ")
			pw, _ := bufio.NewReader(os.Stdin).ReadString('\n')
			if err := server.SetPassword(s.DB(), os.Args[2], strings.TrimRight(pw, "\r\n")); err != nil {
				log.Fatal(err)
			}
			fmt.Fprintln(os.Stderr, "Passwort gesetzt.")
			return
		case "-h", "--help", "help":
			fmt.Println("gw-server [passwd <benutzer>]  – Konfiguration siehe README (GW_* Variablen)")
			return
		}
	}
	s, err := server.New(cfg)
	if err != nil {
		log.Fatal(err)
	}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	if err := s.Run(ctx); err != nil {
		log.Fatal(err)
	}
}
