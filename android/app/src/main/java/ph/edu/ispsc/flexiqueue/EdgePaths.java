package ph.edu.ispsc.flexiqueue;

public final class EdgePaths {
    private EdgePaths() {}

    public static final String TERMUX_HOME = "/data/data/com.termux/files/home";
    public static final String EDGE_DIR = TERMUX_HOME + "/flexiqueue";
    public static final String MARKER_FILE = ".edge-extracted";
    public static final String BUNDLE_ASSET = "edge-bundle.tgz";

    // Termux binary paths (used for RUN_COMMAND intents)
    public static final String TERMUX_SH = "/data/data/com.termux/files/usr/bin/sh";
    public static final String TERMUX_BASH = "/data/data/com.termux/files/usr/bin/bash";

    // Paths to scripts inside the extracted bundle
    public static final String START_STACK_SCRIPT = EDGE_DIR + "/scripts/edge/setup/phone/start-stack.sh";
    public static final String BOOTSTRAP_SCRIPT = EDGE_DIR + "/scripts/edge/setup/phone/bootstrap.sh";
}