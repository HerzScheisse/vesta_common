git grep -I --name-only --fixed-strings -e I18N:: -- *.php *.phtml :(exclude)replacedWebtrees/Functions/FunctionsEdit.php :(exclude)replacedWebtrees/app/GedcomTag.php :(exclude)patchedWebtrees/Module/ClippingsCartModule.php :(exclude)resources/views/layouts/administration.phtml :(exclude)resources/views/layouts/administration_20.phtml | xargs xgettext --package-name=vesta --package-version=1.0 --msgid-bugs-address=ric@richard-cissee.de --output=resources/lang/messages.pot --no-wrap --language=PHP --add-comments=I18N --from-code=utf-8 --keyword --keyword=translate:1 --keyword=translateContext:1c,2 --keyword=plural:1,2
git ls-files *.po | xargs -I INPUT msgmerge --no-wrap --sort-output --no-fuzzy-matching --quiet --backup=off INPUT "resources/lang/messages.pot" -U
pause