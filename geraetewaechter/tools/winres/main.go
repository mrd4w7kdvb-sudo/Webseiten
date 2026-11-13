// winres erzeugt die Windows-Ressourcen (Versionsinformationen mit
// Herstellerangaben und Anwendungsmanifest) für gw-agent.exe als
// COFF-Objektdateien (*.syso), die "go build" automatisch einbindet.
//
// Eigene Implementierung ohne Fremdabhängigkeiten.
//
//	go run ./tools/winres -version 1.2.3 -out cmd/gw-agent
package main

import (
	"bytes"
	"encoding/binary"
	"flag"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"unicode/utf16"
)

const (
	company     = "Stockwerk IT"
	product     = "Gerätewächter"
	description = "Gerätewächter Agent – datensparsame Geräteüberwachung"
	comments    = "Überwacht den Systemzustand und meldet ihn per HTTPS an den Monitoring-Server. Keine Fernsteuerung, keine personenbezogenen Daten."
	internal    = "gw-agent"
	original    = "gw-agent.exe"

	langGerman   = 0x0407
	codepageUTF  = 0x04B0 // Unicode
	rtVersion    = 16
	rtManifest   = 24
	machineAMD64 = 0x8664
	machineARM64 = 0xAA64
	relAMD64Addr = 3 // IMAGE_REL_AMD64_ADDR32NB
	relARM64Addr = 2 // IMAGE_REL_ARM64_ADDR32NB
)

func main() {
	version := flag.String("version", "1.0.0", "Versionsnummer (a.b.c[.d])")
	out := flag.String("out", "cmd/gw-agent", "Zielverzeichnis")
	flag.Parse()

	v := parseVersion(*version)
	verInfo := versionInfo(v, *version)
	manifest := []byte(manifestXML(v))

	for _, a := range []struct {
		name    string
		machine uint16
		rel     uint16
	}{{"amd64", machineAMD64, relAMD64Addr}, {"arm64", machineARM64, relARM64Addr}} {
		obj := coff(a.machine, a.rel, []resource{
			{typ: rtVersion, id: 1, lang: langGerman, data: verInfo},
			{typ: rtManifest, id: 1, lang: 0x0409, data: manifest},
		})
		p := filepath.Join(*out, "rsrc_windows_"+a.name+".syso")
		if err := os.WriteFile(p, obj, 0o644); err != nil {
			log.Fatal(err)
		}
		fmt.Println("geschrieben:", p)
	}
}

func parseVersion(s string) [4]uint16 {
	var v [4]uint16
	for i, p := range strings.SplitN(strings.TrimPrefix(s, "v"), ".", 4) {
		n, _ := strconv.Atoi(strings.TrimFunc(p, func(r rune) bool { return r < '0' || r > '9' }))
		v[i] = uint16(n)
	}
	return v
}

// ---------- VS_VERSIONINFO ----------

type block struct {
	key      string
	text     bool
	value    []byte
	valueLen uint16 // in WCHAR bei Text, sonst Bytes
	children []block
}

func utf16z(s string) []byte {
	u := utf16.Encode([]rune(s))
	b := make([]byte, 2*len(u)+2)
	for i, c := range u {
		binary.LittleEndian.PutUint16(b[2*i:], c)
	}
	return b
}

func pad4(b *bytes.Buffer) {
	for b.Len()%4 != 0 {
		b.WriteByte(0)
	}
}

func (n block) bytes() []byte {
	var b bytes.Buffer
	b.Write(make([]byte, 6)) // wLength, wValueLength, wType
	b.Write(utf16z(n.key))
	pad4(&b)
	b.Write(n.value)
	for _, c := range n.children {
		pad4(&b)
		b.Write(c.bytes())
	}
	out := b.Bytes()
	typ := uint16(0)
	if n.text {
		typ = 1
	}
	binary.LittleEndian.PutUint16(out[0:], uint16(len(out)))
	binary.LittleEndian.PutUint16(out[2:], n.valueLen)
	binary.LittleEndian.PutUint16(out[4:], typ)
	return out
}

func str(key, val string) block {
	v := utf16z(val)
	return block{key: key, text: true, value: v, valueLen: uint16(len(v) / 2)}
}

func versionInfo(v [4]uint16, display string) []byte {
	fixed := make([]byte, 52)
	le := binary.LittleEndian
	le.PutUint32(fixed[0:], 0xFEEF04BD)                     // Signatur
	le.PutUint32(fixed[4:], 0x00010000)                     // Strukturversion
	le.PutUint32(fixed[8:], uint32(v[0])<<16|uint32(v[1]))  // FileVersionMS
	le.PutUint32(fixed[12:], uint32(v[2])<<16|uint32(v[3])) // FileVersionLS
	le.PutUint32(fixed[16:], uint32(v[0])<<16|uint32(v[1])) // ProductVersionMS
	le.PutUint32(fixed[20:], uint32(v[2])<<16|uint32(v[3])) // ProductVersionLS
	le.PutUint32(fixed[24:], 0x3F)                          // FileFlagsMask
	le.PutUint32(fixed[28:], 0)                             // FileFlags
	le.PutUint32(fixed[32:], 0x00040004)                    // VOS_NT_WINDOWS32
	le.PutUint32(fixed[36:], 1)                             // VFT_APP
	display = strings.TrimPrefix(display, "v")

	trans := make([]byte, 4)
	le.PutUint16(trans[0:], langGerman)
	le.PutUint16(trans[2:], codepageUTF)

	root := block{
		key: "VS_VERSION_INFO", value: fixed, valueLen: uint16(len(fixed)),
		children: []block{
			{key: "StringFileInfo", text: true, children: []block{{
				key: fmt.Sprintf("%04X%04X", langGerman, codepageUTF), text: true,
				children: []block{
					str("CompanyName", company),
					str("FileDescription", description),
					str("FileVersion", display),
					str("InternalName", internal),
					str("LegalCopyright", "© "+company),
					str("OriginalFilename", original),
					str("ProductName", product),
					str("ProductVersion", display),
					str("Comments", comments),
				},
			}}},
			{key: "VarFileInfo", text: true, children: []block{
				{key: "Translation", value: trans, valueLen: 4},
			}},
		},
	}
	return root.bytes()
}

func manifestXML(v [4]uint16) string {
	return fmt.Sprintf(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<assembly xmlns="urn:schemas-microsoft-com:asm.v1" manifestVersion="1.0">
  <assemblyIdentity type="win32" name="StockwerkIT.Geraetewaechter.Agent" version="%d.%d.%d.%d" processorArchitecture="*"/>
  <description>Geraetewaechter Agent (Stockwerk IT)</description>
  <trustInfo xmlns="urn:schemas-microsoft-com:asm.v3">
    <security>
      <requestedPrivileges>
        <requestedExecutionLevel level="asInvoker" uiAccess="false"/>
      </requestedPrivileges>
    </security>
  </trustInfo>
  <compatibility xmlns="urn:schemas-microsoft-com:compatibility.v1">
    <application>
      <supportedOS Id="{8e0f7a12-bfb3-4fe8-b9a5-48fd50a15a9a}"/>
      <supportedOS Id="{1f676c76-80e1-4239-95bb-83d0f6d0da78}"/>
      <supportedOS Id="{4a2f28e3-53b9-4441-ba9c-d69d4a4a6e38}"/>
      <supportedOS Id="{35138b9a-5d96-4fbd-8e2d-a2440225f93a}"/>
    </application>
  </compatibility>
  <application xmlns="urn:schemas-microsoft-com:asm.v3">
    <windowsSettings>
      <longPathAware xmlns="http://schemas.microsoft.com/SMI/2016/WindowsSettings">true</longPathAware>
    </windowsSettings>
  </application>
</assembly>
`, v[0], v[1], v[2], v[3])
}

// ---------- Ressourcenverzeichnis + COFF-Objekt ----------

type resource struct {
	typ, id, lang uint32
	data          []byte
}

// coff baut eine Objektdatei mit einer ".rsrc"-Sektion. Die Ressourcen
// werden in aufsteigender Typ-Reihenfolge erwartet (je Typ eine ID/Sprache).
func coff(machine, relType uint16, res []resource) []byte {
	le := binary.LittleEndian
	n := len(res)
	// Aufbau: Wurzelverzeichnis | n Typverzeichnisse | n ID-Verzeichnisse | n Dateneinträge | Daten
	dirSize := func(entries int) int { return 16 + 8*entries }
	rootOff := 0
	typeOff := rootOff + dirSize(n)
	idOff := typeOff + n*dirSize(1)
	entryOff := idOff + n*dirSize(1)
	dataOff := entryOff + n*16

	var sec bytes.Buffer
	writeDir := func(entries [][2]uint32) {
		h := make([]byte, 16)
		le.PutUint16(h[14:], uint16(len(entries))) // nur ID-Einträge
		sec.Write(h)
		for _, e := range entries {
			eb := make([]byte, 8)
			le.PutUint32(eb[0:], e[0])
			le.PutUint32(eb[4:], e[1])
			sec.Write(eb)
		}
	}
	// Wurzel: Typ -> Typverzeichnis
	var root [][2]uint32
	for i, r := range res {
		root = append(root, [2]uint32{r.typ, 0x80000000 | uint32(typeOff+i*dirSize(1))})
	}
	writeDir(root)
	for i, r := range res { // Typ -> ID-Verzeichnis
		writeDir([][2]uint32{{r.id, 0x80000000 | uint32(idOff+i*dirSize(1))}})
	}
	for i, r := range res { // ID -> Sprache -> Dateneintrag
		writeDir([][2]uint32{{r.lang, uint32(entryOff + i*16)}})
	}
	// Dateneinträge; Datenpositionen 8-Byte-ausgerichtet
	offs := make([]int, n)
	pos := dataOff
	for i, r := range res {
		pos = (pos + 7) &^ 7
		offs[i] = pos
		pos += len(r.data)
	}
	var relocs []uint32
	for i, r := range res {
		relocs = append(relocs, uint32(sec.Len())) // Feld OffsetToData wird relokiert (RVA)
		e := make([]byte, 16)
		le.PutUint32(e[0:], uint32(offs[i]))
		le.PutUint32(e[4:], uint32(len(r.data)))
		sec.Write(e)
	}
	for i, r := range res {
		for sec.Len() < offs[i] {
			sec.WriteByte(0)
		}
		sec.Write(r.data)
	}
	for sec.Len()%8 != 0 {
		sec.WriteByte(0)
	}
	raw := sec.Bytes()

	const fileHdr, secHdr = 20, 40
	rawPtr := fileHdr + secHdr
	relPtr := rawPtr + len(raw)
	symPtr := relPtr + 10*len(relocs)

	var o bytes.Buffer
	h := make([]byte, fileHdr)
	le.PutUint16(h[0:], machine)
	le.PutUint16(h[2:], 1) // eine Sektion
	le.PutUint32(h[8:], uint32(symPtr))
	le.PutUint32(h[12:], 1) // ein Symbol
	o.Write(h)

	s := make([]byte, secHdr)
	copy(s[0:8], ".rsrc")
	le.PutUint32(s[16:], uint32(len(raw)))
	le.PutUint32(s[20:], uint32(rawPtr))
	le.PutUint32(s[24:], uint32(relPtr))
	le.PutUint16(s[32:], uint16(len(relocs)))
	le.PutUint32(s[36:], 0x40000040) // initialisierte Daten, lesbar
	o.Write(s)
	o.Write(raw)
	for _, r := range relocs {
		rb := make([]byte, 10)
		le.PutUint32(rb[0:], r)
		le.PutUint32(rb[4:], 0) // Symbol 0 = .rsrc
		le.PutUint16(rb[8:], relType)
		o.Write(rb)
	}
	sym := make([]byte, 18)
	copy(sym[0:8], ".rsrc")
	le.PutUint16(sym[12:], 1) // Sektion 1
	sym[16] = 3               // IMAGE_SYM_CLASS_STATIC
	o.Write(sym)
	st := make([]byte, 4)
	le.PutUint32(st, 4) // leere String-Tabelle
	o.Write(st)
	return o.Bytes()
}
