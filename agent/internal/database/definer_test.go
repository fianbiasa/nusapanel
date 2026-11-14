package database

import (
	"bytes"
	"strings"
	"testing"
)

func TestRewriteDefinersMakesEveryDefinitionTheImportingUsers(t *testing.T) {
	dump := strings.Join([]string{
		"/*!50013 DEFINER=`cpuser`@`localhost` SQL SECURITY DEFINER */",
		"/*!50003 CREATE*/ /*!50017 DEFINER=`cpuser_wp`@`%`*/ /*!50003 TRIGGER t BEFORE INSERT ON a FOR EACH ROW SET NEW.b = 1 */;;",
		"CREATE DEFINER=`cp``user`@`localhost` PROCEDURE `p`()",
		"/*!50106 CREATE*/ /*!50117 DEFINER='cpuser'@'localhost'*/ /*!50106 EVENT `e` ON SCHEDULE EVERY 1 DAY DO SELECT 1 */ ;;",
		"CREATE DEFINER=root@localhost FUNCTION f() RETURNS int RETURN 1",
		"INSERT INTO posts VALUES (1,'DEFINER=`a`@`b`');",
		"  DEFINER=`kept`@`localhost` inside a body",
		"",
	}, "\n")

	var out bytes.Buffer
	if err := RewriteDefiners(strings.NewReader(dump), &out); err != nil {
		t.Fatal(err)
	}

	want := strings.Join([]string{
		"/*!50013 DEFINER=CURRENT_USER SQL SECURITY DEFINER */",
		"/*!50003 CREATE*/ /*!50017 DEFINER=CURRENT_USER*/ /*!50003 TRIGGER t BEFORE INSERT ON a FOR EACH ROW SET NEW.b = 1 */;;",
		"CREATE DEFINER=CURRENT_USER PROCEDURE `p`()",
		"/*!50106 CREATE*/ /*!50117 DEFINER=CURRENT_USER*/ /*!50106 EVENT `e` ON SCHEDULE EVERY 1 DAY DO SELECT 1 */ ;;",
		"CREATE DEFINER=CURRENT_USER FUNCTION f() RETURNS int RETURN 1",
		"INSERT INTO posts VALUES (1,'DEFINER=`a`@`b`');",
		"  DEFINER=`kept`@`localhost` inside a body",
		"",
	}, "\n")

	if out.String() != want {
		t.Errorf("got:\n%s\nwant:\n%s", out.String(), want)
	}
}

func TestRewriteDefinersCopiesLongLinesWhole(t *testing.T) {
	insert := "INSERT INTO a VALUES ('" + strings.Repeat("x", 200<<10) + "');\n"
	view := "/*!50013 DEFINER=`u`@`h` */ " + strings.Repeat("y", 100<<10) + "\n"
	huge := "CREATE TABLE z (c text) COMMENT 'DEFINER=`u`@`h`" + strings.Repeat("z", maxDefinerLine) + "';\n"
	last := "-- no line break at the end"

	var out bytes.Buffer
	if err := RewriteDefiners(strings.NewReader(insert+view+huge+last), &out); err != nil {
		t.Fatal(err)
	}

	want := insert + "/*!50013 DEFINER=CURRENT_USER */ " + strings.Repeat("y", 100<<10) + "\n" + huge + last
	if out.String() != want {
		t.Errorf("got %d bytes, want %d", out.Len(), len(want))
	}
}
