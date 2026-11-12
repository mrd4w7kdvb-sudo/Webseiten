package server

import (
	"embed"
	"io/fs"
	"net/http"
	"strings"
)

//go:embed web/*
var webFS embed.FS

func (s *Server) webHandler() http.Handler {
	sub, _ := fs.Sub(webFS, "web")
	files := http.FileServer(http.FS(sub))
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/" || !strings.Contains(r.URL.Path[1:], ".") {
			w.Header().Set("Cache-Control", "no-store")
			http.ServeFileFS(w, r, sub, "index.html")
			return
		}
		files.ServeHTTP(w, r)
	})
}
