package database

import (
	"bufio"
	"bytes"
	"errors"
	"io"
	"regexp"
)

// maxDefinerLine bounds a line RewriteDefiners looks into: the CREATE of a
// view, trigger, routine or event. Longer ones pass unchanged.
const maxDefinerLine = 1 << 20

// definerPattern is a DEFINER clause as mysqldump writes it, with the user
// and host quoted with backticks or single quotes, or not at all.
var definerPattern = regexp.MustCompile("DEFINER\\s*=\\s*(?:`(?:[^`]|``)*`|'(?:[^'\\\\]|\\\\.|'')*'|[A-Za-z0-9_.$-]+)\\s*@\\s*(?:`(?:[^`]|``)*`|'(?:[^'\\\\]|\\\\.|'')*'|[A-Za-z0-9_.%$-]+)")

// RewriteDefiners copies the dump in to out with the DEFINER of every view,
// trigger, routine and event made CURRENT_USER: the user the dump is
// imported as, the account's owner user (see Import). A dump made on
// another server names users of that server, for which the import may not
// create anything. Only lines that start a definition (with CREATE or a
// versioned comment) are looked into, never the data of the tables.
func RewriteDefiners(in io.Reader, out io.Writer) error {
	reader := bufio.NewReaderSize(in, 64<<10)

	for {
		chunk, err := reader.ReadSlice('\n')
		if len(chunk) > 0 {
			err = writeLine(reader, chunk, err, out)
		}

		if errors.Is(err, io.EOF) {
			return nil
		}

		if err != nil {
			return err
		}
	}
}

// writeLine writes the line that starts with chunk, read up to its end,
// and returns the error reading it ended with (nil at a line break).
func writeLine(reader *bufio.Reader, chunk []byte, err error, out io.Writer) error {
	if !bytes.HasPrefix(chunk, []byte("/*!")) && !bytes.HasPrefix(chunk, []byte("CREATE")) {
		return copyLine(reader, chunk, err, out)
	}

	line := append([]byte(nil), chunk...)

	for errors.Is(err, bufio.ErrBufferFull) && len(line) <= maxDefinerLine {
		chunk, err = reader.ReadSlice('\n')
		line = append(line, chunk...)
	}

	if len(line) > maxDefinerLine {
		return copyLine(reader, line, err, out)
	}

	if _, werr := out.Write(definerPattern.ReplaceAll(line, []byte("DEFINER=CURRENT_USER"))); werr != nil {
		return werr
	}

	return err
}

// copyLine writes chunk and the rest of its line unchanged.
func copyLine(reader *bufio.Reader, chunk []byte, err error, out io.Writer) error {
	if _, werr := out.Write(chunk); werr != nil {
		return werr
	}

	for errors.Is(err, bufio.ErrBufferFull) {
		chunk, err = reader.ReadSlice('\n')

		if _, werr := out.Write(chunk); werr != nil {
			return werr
		}
	}

	return err
}
