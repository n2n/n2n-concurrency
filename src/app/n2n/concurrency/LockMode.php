<?php

namespace n2n\concurrency;

enum LockMode {
	case SHARED;
	case EXCLUSIVE;
}