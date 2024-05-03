<?php

namespace n2n\concurrency\sync;

enum LockMode {
	case SHARED;
	case EXCLUSIVE;
}