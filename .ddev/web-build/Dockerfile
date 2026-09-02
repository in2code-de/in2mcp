# Workaround: git 2.39.5 in the DDEV web image fails on GitHub's HTTP/2 ref
# listing ("could not read Username" / "expected flush after ref listing"),
# which breaks install_nvm.sh during container start and any git based
# composer repository. Forcing HTTP/1.1 for git resolves it.
RUN git config --system http.version HTTP/1.1
